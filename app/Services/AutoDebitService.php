<?php

namespace App\Services;

use App\Mail\AutoDebitMail;
use App\Models\AutoDebitAttempt;
use App\Models\AutoDebitMandate;
use App\Models\AutoDebitSetup;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FrontendUrl;
use App\Support\Recipients;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Tenant auto-debit: rent invoices are charged to a saved card on the due date.
 *
 * A tenant saves a card once (a ₦50 verification checkout that is refunded, or the
 * "save my card" box on a normal payment). Paystack hands back a reusable
 * authorization token — we store only that, encrypted; the card number never
 * touches this server.
 *
 * A daily job ({@see run()}) charges every invoice that falls due on or after the day the
 * card was saved (older arrears are never auto-charged). A failed charge is retried
 * on due+1 and due+3 — three attempts in all, one per day at most. After the last
 * failure the invoice reverts to manual payment, the tenant and the property's managers
 * are told, and auto-debit stays on for future invoices.
 *
 * Successful charges go through PaystackService::verify() → PaymentService::settle(), so they
 * produce exactly the same payment, receipt-style notification and email as a manual online payment.
 */
class AutoDebitService
{
    public const SETUP_AMOUNT = 50;          // ₦, refunded straight after the card is saved

    public const MAX_ATTEMPTS = 3;

    /** Attempt number → days after the due date it runs. */
    private const ATTEMPT_OFFSET_DAYS = [1 => 0, 2 => 1, 3 => 3];

    /** A card pending longer than this with no webhook gets re-checked with Paystack. */
    private const PENDING_RECHECK_MINUTES = 30;

    public function __construct(
        private PaystackService $paystack,
        private PaymentService $payments,
        private NotificationService $notifications,
        private AuditService $audit,
        private PropertyScope $scope,
    ) {}

    public static function isSetupReference(string $reference): bool
    {
        return str_starts_with($reference, 'ADS-');
    }

    // =====================================================================
    // Tenant-facing: status, setup, cancel
    // =====================================================================

    /** @return array<string,mixed> */
    public function status(User $user): array
    {
        $tenantId = $this->tenantIdFor($user);
        $mandate = $this->activeMandate($tenantId);
        $latest = $mandate ?? AutoDebitMandate::query()->where('tenantId', $tenantId)->orderByDesc('enrolledAt')->first();

        return [
            'available' => $this->paystack->configured(),
            'enabled' => $mandate !== null,
            'status' => $latest?->status ?? 'none',     // none | active | cancelled | expired
            'card' => $latest ? $this->cardSummary($latest) : null,
            'enrolledAt' => $latest?->enrolledAt,
            'setupAmount' => self::SETUP_AMOUNT,
            'maxAttempts' => self::MAX_ATTEMPTS,
            'upcoming' => $mandate ? $this->upcoming($mandate) : [],
        ];
    }

    /**
     * Start the ₦50 card-verification checkout.
     *
     * @return array{authorizationUrl:string,reference:string}
     */
    public function startSetup(User $user): array
    {
        abort_unless($this->paystack->configured(), 503, 'Online payments are not configured.');

        $tenantId = $this->tenantIdFor($user);
        $email = Tenant::query()->whereKey($tenantId)->value('email') ?: $user->email;
        $reference = 'ADS-'.Str::upper(Str::random(18));

        $setup = AutoDebitSetup::query()->create([
            'id' => 'ads_'.Str::lower(Str::random(12)),
            'reference' => $reference,
            'tenantId' => $tenantId,
            'userId' => $user->id,
            'amount' => self::SETUP_AMOUNT,
            'status' => 'initiated',
            'createdAt' => now()->toIso8601String(),
        ]);

        $result = $this->paystack->startTransaction(
            $email,
            self::SETUP_AMOUNT * 100,
            $reference,
            ['purpose' => 'auto-debit-card', 'tenantId' => $tenantId],
            ['card'],   // direct debit / transfer can't be charged again later
        );

        if (! $result['ok']) {
            $setup->update(['status' => 'failed']);
            abort(502, $result['message'] ?: 'Could not start the card setup.');
        }

        $this->audit->record($user, 'create', 'AutoDebit', $setup->id, 'Started auto-debit card setup');

        return ['authorizationUrl' => (string) $result['authorizationUrl'], 'reference' => $reference];
    }

    /**
     * Finish a card setup after the tenant returns from Paystack (or the webhook fires).
     * Idempotent. `$actor` is null when called from the webhook.
     *
     * @return array{status:string,message:string,card:?array<string,mixed>}
     */
    public function completeSetup(string $reference, ?User $actor = null): array
    {
        /** @var AutoDebitSetup $setup */
        $setup = AutoDebitSetup::query()->where('reference', $reference)->firstOrFail();

        if ($actor) {
            abort_unless(in_array($setup->tenantId, $actor->tenantRecordIds(), true), 403, 'This card setup belongs to someone else.');
        }

        if ($setup->status === 'verified') {
            $card = $this->activeMandate($setup->tenantId);

            return ['status' => 'approved', 'message' => 'Auto-debit is on.', 'card' => $card ? $this->cardSummary($card) : null];
        }

        $data = $this->paystack->fetchTransaction($reference);

        if (($data['status'] ?? null) !== 'success') {
            if (in_array($data['status'] ?? '', ['failed', 'abandoned', 'reversed'], true)) {
                $setup->update(['status' => 'failed']);
            }

            return ['status' => 'failed', 'message' => $data['gateway_response'] ?? 'The card check was not completed.', 'card' => null];
        }

        $mandate = $this->saveMandate($setup->tenantId, $setup->userId, $data);
        $setup->update(['status' => $mandate ? 'verified' : 'failed']);

        // The ₦50 was only a verification — give it back (best effort; Paystack processes it asynchronously).
        $this->paystack->refund($reference);

        if (! $mandate) {
            return ['status' => 'failed', 'message' => 'This card can’t be saved for automatic payments. Try a different card.', 'card' => null];
        }

        return ['status' => 'approved', 'message' => 'Auto-debit is on.', 'card' => $this->cardSummary($mandate)];
    }

    /** The tenant ticked "save this card" on a normal checkout that just succeeded. */
    public function captureFromTransaction(string $tenantId, array $transaction): void
    {
        $this->saveMandate($tenantId, null, $transaction);
    }

    public function cancel(User $user, string $reason = 'Cancelled by tenant'): bool
    {
        $mandate = $this->activeMandate($this->tenantIdFor($user));
        if (! $mandate) {
            return false;
        }

        $this->deactivate($mandate, 'cancelled', $reason);
        $this->audit->record($user, 'delete', 'AutoDebit', $mandate->id, 'Turned off auto-debit');

        $this->tell($mandate->tenantId, 'Auto-debit turned off', 'Your card will no longer be charged automatically. Pay each invoice yourself from now on.', '/portal/pay', [
            'subject' => 'Auto-debit turned off',
            'heading' => 'Auto-debit is off',
            'intro' => 'Your saved card will no longer be charged automatically. You can turn it back on any time.',
            'lines' => ["Card: {$this->cardLabel($mandate)}"],
            'ctaLabel' => 'Manage payments',
            'ctaUrl' => FrontendUrl::to('/portal/pay'),
        ]);

        return true;
    }

    // =====================================================================
    // The daily run
    // =====================================================================

    /**
     * @param  array{date?:Carbon|null,invoice?:string|null,force?:bool,dryRun?:bool,amount?:int|null}  $options
     * @return array<string,mixed> counts + a per-invoice log for the command to print
     */
    public function run(array $options = []): array
    {
        $today = ($options['date'] ?? now())->copy()->startOfDay();
        $force = (bool) ($options['force'] ?? false);
        $dry = (bool) ($options['dryRun'] ?? false);

        $stats = ['mandates' => 0, 'charged' => 0, 'succeeded' => 0, 'failed' => 0, 'pending' => 0,
            'finalFailures' => 0, 'reminded' => 0, 'expired' => 0, 'reconciled' => 0, 'skipped' => 0, 'log' => []];

        if (! $dry) {
            $stats['expired'] = $this->expireCards($today);
            $stats['reconciled'] = $this->reconcilePending();
        }

        $mandates = AutoDebitMandate::query()->where('status', 'active')->get();
        $stats['mandates'] = $mandates->count();

        foreach ($mandates as $mandate) {
            $invoices = Invoice::query()
                ->where('tenantId', $mandate->tenantId)
                ->whereNotIn('status', ['cancelled', 'fully-paid'])
                ->whereColumn('amountPaid', '<', 'total')
                ->when($options['invoice'] ?? null, fn ($q, $i) => $q->where(fn ($w) => $w->where('id', $i)->orWhere('code', $i)))
                ->orderBy('dueDate')
                ->get();

            foreach ($invoices as $invoice) {
                $attemptNo = $this->nextAttemptNo($invoice, $mandate, $today, $force);

                if ($attemptNo === null) {
                    // Not due yet — but warn the tenant the day before the first attempt.
                    if (! $dry && $this->remindIfDueTomorrow($invoice, $mandate, $today)) {
                        $stats['reminded']++;
                    }
                    $stats['skipped']++;

                    continue;
                }

                $outstanding = max(0, (int) $invoice->total - (int) $invoice->amountPaid);
                $amount = isset($options['amount']) && $force ? min($outstanding, (int) $options['amount']) : $outstanding;

                if ($dry) {
                    $stats['log'][] = 'would charge ₦'.number_format($amount)." for {$invoice->code} (attempt {$attemptNo}) — {$this->cardLabel($mandate)}";
                    $stats['charged']++;

                    continue;
                }

                $attempt = $this->charge($invoice, $mandate, $attemptNo, $amount, $today);
                if (! $attempt) {
                    $stats['skipped']++;

                    continue;
                }

                $stats['charged']++;
                $stats[match ($attempt->status) {
                    'success' => 'succeeded', 'failed' => 'failed', default => 'pending'
                }]++;
                if ($attempt->status === 'failed' && $attempt->nextAttemptOn === null) {
                    $stats['finalFailures']++;
                }
                $stats['log'][] = "{$invoice->code}: attempt {$attemptNo} ₦".number_format($amount)." → {$attempt->status}".($attempt->reason ? " ({$attempt->reason})" : '');
            }
        }

        return $stats;
    }

    /**
     * Which attempt (1–3) should run for this invoice today, or null.
     * Rules: never after a success or while one is in flight; never for invoices that fell due before the
     * card was saved; at most one attempt a day; attempts at due, due+1, due+3.
     */
    public function nextAttemptNo(Invoice $invoice, AutoDebitMandate $mandate, Carbon $today, bool $force = false): ?int
    {
        $outstanding = (int) $invoice->total - (int) $invoice->amountPaid;
        if ($outstanding <= 0 || $invoice->status === 'cancelled' || ! $invoice->dueDate) {
            return null;
        }

        $attempts = AutoDebitAttempt::query()->where('invoiceId', $invoice->id)->orderBy('attemptNo')->get();

        if ($attempts->contains(fn ($a) => in_array($a->status, ['success', 'pending'], true))) {
            return null;
        }

        $due = Carbon::parse($invoice->dueDate)->startOfDay();
        $enrolled = Carbon::parse($mandate->enrolledAt)->startOfDay();

        if (! $force && $due->lt($enrolled)) {
            return null; // arrears from before the tenant opted in are never auto-charged
        }

        $next = $attempts->count() + 1;
        if ($next > self::MAX_ATTEMPTS) {
            return null;
        }

        // Someone is paying this invoice by hand right now — don't collide with them.
        try {
            $this->payments->assertNoOpenPayment($invoice->id);
        } catch (HttpException) {
            return null;
        }

        if ($force) {
            return $next;
        }

        $runsOn = $due->copy()->addDays(self::ATTEMPT_OFFSET_DAYS[$next]);
        $last = $attempts->last();

        if ($today->lt($runsOn)) {
            return null;
        }
        // If the job missed days, don't chase an invoice that is now well past its schedule on the first try.
        if ($next === 1 && $due->diffInDays($today) > 3) {
            return null;
        }
        if ($last && Carbon::parse($last->attemptedAt)->startOfDay()->gte($today)) {
            return null; // already tried today
        }

        return $next;
    }

    // =====================================================================
    // Charging
    // =====================================================================

    private function charge(Invoice $invoice, AutoDebitMandate $mandate, int $attemptNo, int $amount, Carbon $today): ?AutoDebitAttempt
    {
        $reference = 'ADB-'.Str::upper(Str::random(18));

        // The unique (invoiceId, attemptNo) index is the double-charge guard: if a parallel run got
        // here first this insert fails and we back off without touching the card.
        try {
            $attempt = AutoDebitAttempt::query()->create([
                'id' => 'adt_'.Str::lower(Str::random(12)),
                'invoiceId' => $invoice->id,
                'tenantId' => $invoice->tenantId,
                'mandateId' => $mandate->id,
                'attemptNo' => $attemptNo,
                'status' => 'pending',
                'amount' => $amount,
                'attemptedAt' => now()->toIso8601String(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $paymentId = 'pay_'.Str::lower(Str::random(12));
        $payment = Payment::query()->create([
            'id' => $paymentId,
            'code' => 'PAY-'.Str::upper(substr($paymentId, -5)),
            'invoiceId' => $invoice->id,
            'leaseId' => $invoice->leaseId,
            'unitId' => $invoice->unitId,
            'propertyId' => $invoice->propertyId,
            'tenantId' => $invoice->tenantId,
            'type' => 'rent',
            'method' => 'online',
            'amount' => $amount,
            'paymentDate' => now()->toDateString(),
            'reference' => $reference,
            'payerName' => Tenant::query()->whereKey($invoice->tenantId)->value('name') ?? 'Tenant',
            'description' => "Automatic debit for invoice {$invoice->code} (attempt {$attemptNo} of ".self::MAX_ATTEMPTS.')',
            'status' => 'initiated',
            'submittedAt' => now()->toIso8601String(),
            'gateway' => 'paystack',
            'gatewayReference' => $reference,
            'saveCard' => false,
        ]);
        $attempt->update(['paymentId' => $payment->id]);

        $result = $this->paystack->chargeAuthorization(
            $mandate->email,
            $amount * 100,
            $mandate->authorizationCode,
            $reference,
            ['paymentId' => $payment->id, 'invoiceId' => $invoice->id, 'invoiceCode' => $invoice->code, 'tenantId' => $invoice->tenantId, 'autoDebit' => true, 'attemptNo' => $attemptNo],
        );

        switch ($result['status']) {
            case 'success':
                // Re-confirm with Paystack and settle through the normal path (idempotent).
                try {
                    $settled = $this->paystack->verify($reference);
                } catch (\Throwable $e) {
                    Log::warning('Auto-debit: verify after charge failed', ['reference' => $reference, 'error' => $e->getMessage()]);
                    $settled = null;
                }
                if ($settled && $settled->status === 'approved') {
                    $attempt->update(['status' => 'success', 'nextAttemptOn' => null]);
                    $this->afterSuccess($invoice, $mandate, $amount);
                } else {
                    $attempt->update(['status' => 'pending', 'reason' => 'Awaiting confirmation']);
                }
                break;

            case 'failed':
                $this->failAttempt($attempt, $payment, $invoice, $mandate, $result['message'] ?: 'The charge was declined.', $today);
                // A dead authorization (revoked / deactivated) can't be retried — stop using it.
                if (! $result['httpOk'] && preg_match('/authorization/i', (string) $result['message'])) {
                    $this->deactivate($mandate, 'expired', (string) $result['message']);
                }
                break;

            default: // 'unknown', 'pending', 'ongoing', 'pay_offline', … — outcome not known yet; reconcile later
                $payment->update(['gatewayStatus' => $result['status']]);
                $attempt->update(['status' => 'pending', 'reason' => 'Awaiting confirmation']);
        }

        return $attempt->refresh();
    }

    private function failAttempt(AutoDebitAttempt $attempt, Payment $payment, Invoice $invoice, AutoDebitMandate $mandate, string $reason, Carbon $today): void
    {
        $payment->update(['status' => 'failed', 'gatewayStatus' => 'failed', 'rejectionReason' => $reason]);

        $isLast = $attempt->attemptNo >= self::MAX_ATTEMPTS;
        $due = Carbon::parse($invoice->dueDate)->startOfDay();
        $nextOn = $isLast ? null : $due->copy()->addDays(self::ATTEMPT_OFFSET_DAYS[$attempt->attemptNo + 1]);

        // A retry can never be scheduled in the past (e.g. a late first attempt) — push it to tomorrow.
        if ($nextOn && $nextOn->lte($today)) {
            $nextOn = $today->copy()->addDay();
        }

        $attempt->update(['status' => 'failed', 'reason' => $reason, 'nextAttemptOn' => $nextOn?->toDateString()]);

        $amount = '₦'.number_format($attempt->amount);

        if (! $isLast) {
            $this->tell($invoice->tenantId, 'Automatic payment failed', "We couldn't charge {$amount} for invoice {$invoice->code}: {$reason}. We'll try again on {$nextOn->toFormattedDateString()}.", "/portal/bills/{$invoice->id}", [
                'subject' => "Automatic payment for {$invoice->code} didn't go through",
                'heading' => 'We couldn’t take your rent payment',
                'intro' => "Your card was not charged for invoice {$invoice->code}. We'll try again automatically — or you can pay now.",
                'lines' => ["Amount: {$amount}", "Reason: {$reason}", "Next attempt: {$nextOn->toFormattedDateString()}", "Attempt {$attempt->attemptNo} of ".self::MAX_ATTEMPTS],
                'ctaLabel' => 'Pay now',
                'ctaUrl' => FrontendUrl::to("/portal/bills/{$invoice->id}"),
                'footnote' => 'Tip: make sure your card has enough balance and is still valid.',
            ], $invoice->id.'_a'.$attempt->attemptNo);

            return;
        }

        // Final failure: back to manual for this invoice.
        $this->tell($invoice->tenantId, 'Automatic payment failed — please pay manually', "We couldn't charge {$amount} for invoice {$invoice->code} after ".self::MAX_ATTEMPTS.' attempts. Please pay it yourself.', "/portal/bills/{$invoice->id}", [
            'subject' => "Action needed: pay invoice {$invoice->code} manually",
            'heading' => 'Please pay this invoice yourself',
            'intro' => 'We tried to charge your card '.self::MAX_ATTEMPTS." times for invoice {$invoice->code} and it didn't go through, so it's now back to manual payment.",
            'lines' => ["Amount: {$amount}", "Last reason: {$reason}"],
            'ctaLabel' => 'Pay now',
            'ctaUrl' => FrontendUrl::to("/portal/bills/{$invoice->id}"),
            'footnote' => 'Auto-debit stays on for your future invoices. You can update or remove your card on the same page.',
        ], $invoice->id.'_final');

        $this->alertManagers($invoice, $mandate, $amount, $reason);
    }

    private function afterSuccess(Invoice $invoice, AutoDebitMandate $mandate, int $amount): void
    {
        $this->tell($invoice->tenantId, 'Rent paid automatically', '₦'.number_format($amount)." was charged to {$this->cardLabel($mandate)} for invoice {$invoice->code}.", '/portal/payments', null, $invoice->id.'_ok');
    }

    // =====================================================================
    // Housekeeping
    // =====================================================================

    /** Cards past their expiry month can't be charged: flag them and ask the tenant for a new one. */
    private function expireCards(Carbon $today): int
    {
        $n = 0;

        foreach (AutoDebitMandate::query()->where('status', 'active')->get() as $m) {
            if (! $m->expMonth || ! $m->expYear) {
                continue;
            }
            $expiresEnd = Carbon::createFromDate((int) $m->expYear, (int) $m->expMonth, 1)->endOfMonth();
            if ($expiresEnd->lt($today)) {
                $this->deactivate($m, 'expired', 'Card expired');
                $this->tell($m->tenantId, 'Your auto-debit card has expired', "The card {$this->cardLabel($m)} has expired, so auto-debit is paused. Add a new card to turn it back on.", '/portal/pay', [
                    'subject' => 'Your auto-debit card has expired',
                    'heading' => 'Auto-debit paused — card expired',
                    'intro' => 'We can’t charge an expired card. Add a new card to keep your rent on auto-pilot.',
                    'lines' => ["Card: {$this->cardLabel($m)}"],
                    'ctaLabel' => 'Add a new card',
                    'ctaUrl' => FrontendUrl::to('/portal/pay'),
                ]);
                $n++;
            }
        }

        return $n;
    }

    /** Charges whose outcome we never heard about: ask Paystack, then settle or fail them. */
    private function reconcilePending(): int
    {
        $n = 0;
        $cutoff = now()->subMinutes(self::PENDING_RECHECK_MINUTES)->toIso8601String();

        $pending = AutoDebitAttempt::query()->where('status', 'pending')->where('attemptedAt', '<=', $cutoff)->get();

        foreach ($pending as $attempt) {
            $payment = Payment::query()->find($attempt->paymentId);
            $invoice = Invoice::query()->find($attempt->invoiceId);
            $mandate = AutoDebitMandate::query()->find($attempt->mandateId);
            if (! $payment || ! $invoice || ! $mandate) {
                continue;
            }

            try {
                $result = $this->paystack->verify((string) $payment->gatewayReference);
            } catch (\Throwable $e) {
                continue; // still can't tell — leave it pending, try again next run
            }

            if ($result->status === 'approved') {
                $attempt->update(['status' => 'success', 'reason' => null, 'nextAttemptOn' => null]);
                $this->afterSuccess($invoice, $mandate, (int) $attempt->amount);
                $n++;
            } elseif ($result->status === 'failed') {
                $this->failAttempt($attempt, $result, $invoice, $mandate, $result->rejectionReason ?: 'The charge did not complete.', now()->startOfDay());
                $n++;
            }
        }

        return $n;
    }

    /** The day before the first attempt, tell the tenant what's about to be charged. */
    private function remindIfDueTomorrow(Invoice $invoice, AutoDebitMandate $mandate, Carbon $today): bool
    {
        if (! $invoice->dueDate || ! Carbon::parse($invoice->dueDate)->startOfDay()->eq($today->copy()->addDay())) {
            return false;
        }
        if (Carbon::parse($invoice->dueDate)->startOfDay()->lt(Carbon::parse($mandate->enrolledAt)->startOfDay())) {
            return false;
        }
        if (AutoDebitAttempt::query()->where('invoiceId', $invoice->id)->exists()) {
            return false;
        }

        $outstanding = max(0, (int) $invoice->total - (int) $invoice->amountPaid);
        $amount = '₦'.number_format($outstanding);
        $on = Carbon::parse($invoice->dueDate)->toFormattedDateString();

        return $this->tell($invoice->tenantId, 'Automatic payment tomorrow', "{$amount} will be charged to {$this->cardLabel($mandate)} on {$on} for invoice {$invoice->code}.", "/portal/bills/{$invoice->id}", [
            'subject' => "We'll charge {$amount} tomorrow for {$invoice->code}",
            'heading' => 'Your rent is due tomorrow',
            'intro' => 'Your saved card will be charged automatically. Nothing for you to do — just make sure the funds are there.',
            'lines' => ["Invoice: {$invoice->code}", "Amount: {$amount}", "Charge date: {$on}", "Card: {$this->cardLabel($mandate)}"],
            'ctaLabel' => 'Review payments',
            'ctaUrl' => FrontendUrl::to("/portal/bills/{$invoice->id}"),
        ], $invoice->id.'_remind');
    }

    private function alertManagers(Invoice $invoice, AutoDebitMandate $mandate, string $amount, string $reason): void
    {
        $tenantName = Tenant::query()->whereKey($invoice->tenantId)->value('name') ?? 'A tenant';
        $managers = Recipients::propertyManagers($invoice->propertyId);

        foreach ($managers as $manager) {
            $this->notifications->push([
                'id' => "ntf_adb_{$invoice->id}_final_{$manager->id}",
                'kind' => 'payment',
                'title' => 'Auto-debit failed',
                'body' => "{$tenantName}'s automatic payment of {$amount} for {$invoice->code} failed after ".self::MAX_ATTEMPTS." attempts ({$reason}). It's back to manual payment.",
                'forUserId' => $manager->id,
                'link' => "/invoices/{$invoice->id}",
            ]);
        }

        if ($managers->isNotEmpty()) {
            Mail::to($managers->all())->queue(new AutoDebitMail([
                'subject' => "Auto-debit failed for {$invoice->code}",
                'heading' => 'An automatic payment failed',
                'intro' => "{$tenantName}'s card could not be charged for invoice {$invoice->code} after ".self::MAX_ATTEMPTS.' attempts. The tenant has been asked to pay manually.',
                'lines' => ["Tenant: {$tenantName}", "Amount: {$amount}", "Reason: {$reason}"],
                'ctaLabel' => 'View the invoice',
                'ctaUrl' => FrontendUrl::to("/invoices/{$invoice->id}"),
            ]));
        }
    }

    // =====================================================================
    // Mandates
    // =====================================================================

    /** @param  array<string,mixed>  $transaction  Paystack transaction data (carries `authorization` + `customer`) */
    private function saveMandate(string $tenantId, ?string $userId, array $transaction): ?AutoDebitMandate
    {
        $auth = $transaction['authorization'] ?? [];

        // Only a reusable card token can be charged later (not transfers / USSD / one-off cards).
        if (empty($auth['authorization_code']) || empty($auth['reusable']) || ($auth['channel'] ?? 'card') !== 'card') {
            return null;
        }

        $email = $transaction['customer']['email']
            ?? Tenant::query()->whereKey($tenantId)->value('email')
            ?? '';
        if ($email === '') {
            return null;
        }

        // One active card per tenant: a new card replaces the old one.
        foreach (AutoDebitMandate::query()->where('tenantId', $tenantId)->where('status', 'active')->get() as $old) {
            if ($old->signature && $old->signature === ($auth['signature'] ?? null)) {
                // Same physical card re-saved — refresh the token rather than duplicate.
                $old->update(['authorizationCode' => $auth['authorization_code'], 'email' => $email]);

                return $old->refresh();
            }
            $this->deactivate($old, 'cancelled', 'Replaced by a new card');
        }

        $mandate = AutoDebitMandate::query()->create([
            'id' => 'adm_'.Str::lower(Str::random(12)),
            'tenantId' => $tenantId,
            'userId' => $userId,
            'email' => $email,
            'authorizationCode' => $auth['authorization_code'],
            'cardBrand' => $auth['brand'] ?? $auth['card_type'] ?? null,
            'last4' => $auth['last4'] ?? null,
            'expMonth' => $auth['exp_month'] ?? null,
            'expYear' => $auth['exp_year'] ?? null,
            'bank' => $auth['bank'] ?? null,
            'signature' => $auth['signature'] ?? null,
            'status' => 'active',
            'enrolledAt' => now()->toIso8601String(),
        ]);

        $this->tell($tenantId, 'Auto-debit is on', "Your rent will be charged to {$this->cardLabel($mandate)} on each due date.", '/portal/pay', [
            'subject' => 'Auto-debit is on',
            'heading' => 'Auto-debit is on',
            'intro' => 'From now on your rent is charged to this card on each invoice’s due date. We’ll remind you the day before.',
            'lines' => ["Card: {$this->cardLabel($mandate)}", 'If a charge fails we retry twice, then ask you to pay by hand.'],
            'ctaLabel' => 'Manage auto-debit',
            'ctaUrl' => FrontendUrl::to('/portal/pay'),
            'footnote' => 'We never see or store your card number — only a secure token from Paystack.',
        ]);

        return $mandate;
    }

    private function deactivate(AutoDebitMandate $mandate, string $status, string $reason, bool $revoke = true): void
    {
        $code = $mandate->authorizationCode;
        $mandate->update(['status' => $status, 'cancelledAt' => now()->toIso8601String(), 'cancelReason' => $reason]);

        if ($revoke) {
            $this->paystack->deactivateAuthorization($code);
        }
    }

    private function activeMandate(string $tenantId): ?AutoDebitMandate
    {
        return AutoDebitMandate::query()->where('tenantId', $tenantId)->where('status', 'active')->first();
    }

    /** @return array<int,array<string,mixed>> */
    private function upcoming(AutoDebitMandate $mandate): array
    {
        $enrolled = Carbon::parse($mandate->enrolledAt)->startOfDay();

        return Invoice::query()
            ->where('tenantId', $mandate->tenantId)
            ->whereNotIn('status', ['cancelled', 'fully-paid'])
            ->whereColumn('amountPaid', '<', 'total')
            ->whereDate('dueDate', '>=', $enrolled)
            ->orderBy('dueDate')
            ->limit(6)
            ->get()
            ->map(function (Invoice $inv) {
                $attempts = AutoDebitAttempt::query()->where('invoiceId', $inv->id)->orderBy('attemptNo')->get();
                $last = $attempts->last();

                return [
                    'invoiceId' => $inv->id,
                    'code' => $inv->code,
                    'dueDate' => $inv->dueDate,
                    'amount' => max(0, (int) $inv->total - (int) $inv->amountPaid),
                    'state' => $this->stateFor($attempts, scheduled: true),
                    'attempts' => $attempts->count(),
                    'nextAttemptOn' => $last?->nextAttemptOn,
                    'lastReason' => $last?->status === 'failed' ? $last->reason : null,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    private function cardSummary(AutoDebitMandate $m): array
    {
        return [
            'brand' => $m->cardBrand,
            'last4' => $m->last4,
            'expMonth' => $m->expMonth,
            'expYear' => $m->expYear,
            'bank' => $m->bank,
            'label' => $this->cardLabel($m),
        ];
    }

    private function cardLabel(AutoDebitMandate $m): string
    {
        return trim(ucfirst((string) ($m->cardBrand ?: 'Card')).' •••• '.($m->last4 ?: '????'));
    }

    // =====================================================================
    // Staff view (owners / managers / finance / admin)
    // =====================================================================

    private const STAFF_ROLES = ['admin', 'owner', 'manager', 'finance'];

    /**
     * Auto-debit state for every invoice the caller can see that either belongs to an enrolled tenant
     * or has had a charge attempt. Only the state is exposed — never the card or its token.
     *
     * @return array{enrolledTenants:array<int,string>,invoices:array<string,array<string,mixed>>,counts:array<string,int>}
     */
    public function overview(User $user): array
    {
        $this->assertStaff($user);

        $visible = $this->scope->visibleIds($user);
        $mandates = AutoDebitMandate::query()->where('status', 'active')->get()->keyBy('tenantId');
        $attemptedIds = AutoDebitAttempt::query()->select('invoiceId');

        $invoices = Invoice::query()
            ->when($visible !== null, fn ($q) => $q->whereIn('propertyId', $visible ?: ['__none__']))
            ->where(fn ($q) => $q->whereIn('tenantId', $mandates->keys()->all() ?: ['__none__'])->orWhereIn('id', $attemptedIds))
            ->get(['id', 'tenantId', 'propertyId', 'dueDate', 'total', 'amountPaid', 'status']);

        $attempts = AutoDebitAttempt::query()
            ->whereIn('invoiceId', $invoices->pluck('id')->all() ?: ['__none__'])
            ->orderBy('attemptNo')
            ->get()
            ->groupBy('invoiceId');

        $rows = [];
        $counts = ['scheduled' => 0, 'processing' => 0, 'retrying' => 0, 'manual' => 0, 'paid' => 0, 'partial' => 0];

        foreach ($invoices as $inv) {
            $history = $attempts->get($inv->id, collect());
            $mandate = $mandates->get($inv->tenantId);
            $outstanding = max(0, (int) $inv->total - (int) $inv->amountPaid);

            // No charge yet: only meaningful if it will actually be charged (enrolled, unpaid, due on/after opting in).
            $scheduled = $history->isEmpty() && $mandate && $outstanding > 0 && $inv->status !== 'cancelled'
                && $inv->dueDate && Carbon::parse($inv->dueDate)->startOfDay()->gte(Carbon::parse($mandate->enrolledAt)->startOfDay());

            if ($history->isEmpty() && ! $scheduled) {
                continue;
            }

            $state = $this->stateFor($history, scheduled: $scheduled, outstanding: $outstanding);
            $last = $history->last();
            $counts[$state] = ($counts[$state] ?? 0) + 1;

            $rows[$inv->id] = [
                'state' => $state,
                'attempts' => $history->count(),
                'nextAttemptOn' => $last?->nextAttemptOn,
                'lastReason' => $last?->status === 'failed' ? $last->reason : null,
                'dueDate' => $inv->dueDate,
            ];
        }

        return [
            'enrolledTenants' => $invoices->pluck('tenantId')->unique()->filter(fn ($t) => $mandates->has($t))->values()->all(),
            'invoices' => $rows,
            'counts' => $counts,
        ];
    }

    /**
     * One invoice's auto-debit story: whether its tenant is enrolled and every attempt so far.
     *
     * @return array{enrolled:bool,state:?string,attempts:array<int,array<string,mixed>>}
     */
    public function invoiceDetail(User $user, string $invoiceId): array
    {
        $this->assertStaff($user);

        $invoice = Invoice::query()->findOrFail($invoiceId);
        abort_unless($this->scope->canSee($user, $invoice->propertyId), 403, 'That invoice is outside your access.');

        $mandate = $this->activeMandate((string) $invoice->tenantId);
        $history = AutoDebitAttempt::query()->where('invoiceId', $invoice->id)->orderBy('attemptNo')->get();
        $outstanding = max(0, (int) $invoice->total - (int) $invoice->amountPaid);

        $scheduled = $history->isEmpty() && $mandate && $outstanding > 0 && $invoice->status !== 'cancelled'
            && $invoice->dueDate && Carbon::parse($invoice->dueDate)->startOfDay()->gte(Carbon::parse($mandate->enrolledAt)->startOfDay());

        return [
            'enrolled' => $mandate !== null,
            'state' => ($history->isEmpty() && ! $scheduled) ? null : $this->stateFor($history, scheduled: $scheduled, outstanding: $outstanding),
            'maxAttempts' => self::MAX_ATTEMPTS,
            'attempts' => $history->map(fn (AutoDebitAttempt $a) => [
                'attemptNo' => $a->attemptNo,
                'status' => $a->status,
                'amount' => $a->amount,
                'reason' => $a->reason,
                'attemptedAt' => $a->attemptedAt,
                'nextAttemptOn' => $a->nextAttemptOn,
                'paymentId' => $a->paymentId,
            ])->values()->all(),
        ];
    }

    private function assertStaff(User $user): void
    {
        abort_unless(in_array($user->role, self::STAFF_ROLES, true), 403, 'Auto-debit status is for property staff.');
    }

    /**
     * scheduled | processing | retrying | manual | paid | partial — the one definition both the
     * tenant card and the staff views use.
     *
     * @param  Collection<int,AutoDebitAttempt>  $attempts
     */
    private function stateFor($attempts, bool $scheduled = false, ?int $outstanding = null): string
    {
        $last = $attempts->last();

        return match (true) {
            $attempts->contains('status', 'success') => ($outstanding ?? 1) <= 0 ? 'paid' : ($outstanding === null ? 'scheduled' : 'partial'),
            $attempts->contains('status', 'pending') => 'processing',
            $attempts->count() >= self::MAX_ATTEMPTS && $last?->status === 'failed' => 'manual',
            $last?->status === 'failed' => 'retrying',
            default => 'scheduled',
        };
    }

    // =====================================================================
    // Plumbing
    // =====================================================================

    private function tenantIdFor(User $user): string
    {
        $ids = $user->tenantRecordIds();
        abort_if($ids === [], 403, 'Auto-debit is for tenants.');

        return $ids[0];
    }

    /**
     * In-app notification + (optionally) a queued email to the tenant's login(s).
     * `$key` makes the notification idempotent; returns true the first time it is created.
     *
     * @param  array<string,mixed>|null  $mail
     */
    private function tell(string $tenantId, string $title, string $body, string $link, ?array $mail, ?string $key = null): bool
    {
        $notification = $this->notifications->push(array_filter([
            'id' => $key ? "ntf_adb_{$key}" : null,
            'kind' => 'payment',
            'title' => $title,
            'body' => $body,
            'forUserId' => $tenantId,
            'link' => $link,
        ], fn ($v) => $v !== null));

        $fresh = $notification->wasRecentlyCreated;

        if ($mail && ($fresh || $key === null)) {
            $recipients = Recipients::tenantUsers($tenantId);
            if ($recipients->isNotEmpty()) {
                Mail::to($recipients->all())->queue(new AutoDebitMail($mail));
            }
        }

        return $fresh;
    }
}

<?php

namespace App\Services;

use App\Mail\PaymentApprovedMail;
use App\Mail\PaymentInfoRequestedMail;
use App\Mail\PaymentRejectedMail;
use App\Mail\PaymentSubmittedMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FrontendUrl;
use App\Support\Recipients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Payments and the receipt-verification workflow. Ports the reducers
 * `submitPayment`, `approvePayment`, `rejectPayment`, `requestPaymentInfo` and
 * `refundPayment` from the frontend's `src/store/dataSlice.ts`.
 */
class PaymentService extends CrudService
{
    protected string $model = Payment::class;

    protected array $searchable = ['code', 'reference', 'payerName', 'description'];

    protected array $filterable = ['propertyId', 'tenantId', 'invoiceId', 'leaseId', 'status', 'method', 'type'];

    protected array $sortable = ['id', 'code', 'paymentDate', 'submittedAt', 'amount', 'status'];

    protected string $defaultSort = '-submittedAt';

    protected string $idPrefix = 'pay';

    public function __construct(
        PropertyScope $scope,
        private AuditService $audit,
        private NotificationService $notifications,
        private InvoiceService $invoices,
    ) {
        parent::__construct($scope);
    }

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $this->scopeByProperty($query, $user);
    }

    // Payments are a ledger: they only enter through submit() and only change through the
    // approve / reject / refund workflow. The generic REST routes must not write them directly.

    public function create(User $user, array $data): Model
    {
        abort(405, 'Payments are recorded through /payments/submit.');
    }

    public function update(User $user, string $id, array $data): Model
    {
        abort(405, 'A payment changes only through approval, rejection or refund.');
    }

    public function delete(User $user, string $id): void
    {
        abort(405, 'Payments cannot be deleted.');
    }

    /**
     * Record a payment against an invoice. Enters the verification queue; the
     * invoice balance is only touched on approval.
     */
    public function submit(User $user, array $input): Payment
    {
        $this->authorizeCollection($user, 'manage');

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->findOrFail($input['invoiceId']);
        $this->authorize($user, 'view', $invoice); // must be able to see the invoice

        $this->assertNoOpenPayment($invoice->id);

        $id = $input['id'] ?? 'pay_'.Str::lower(Str::random(12));

        abort_if(Payment::query()->whereKey($id)->exists(), 409, "Payment {$id} already exists.");

        $payment = Payment::query()->create([
            'id' => $id,
            'code' => $input['code'] ?? 'PAY-'.Str::upper(substr($id, -5)),
            'invoiceId' => $invoice->id,
            'leaseId' => $invoice->leaseId,
            'unitId' => $invoice->unitId,
            'propertyId' => $invoice->propertyId,
            'tenantId' => $invoice->tenantId,
            'type' => $input['type'],
            'method' => $input['method'],
            'amount' => (int) $input['amount'],
            'paymentDate' => $input['paymentDate'],
            'bankName' => $input['bankName'] ?? null,
            'reference' => $input['reference'],
            'payerName' => $input['payerName'] ?? null,
            'description' => $input['description'] ?? null,
            'receiptFileName' => $input['receiptFileName'] ?? null,
            'receiptPath' => $this->storeReceipt($id, $input['receiptDataUrl'] ?? null),
            'supportingFileName' => $input['supportingFileName'] ?? null,
            'status' => 'pending-verification',
            'submittedAt' => now()->toIso8601String(),
        ]);

        if ($invoice->amountPaid === 0 && $invoice->status !== 'cancelled') {
            $invoice->update(['status' => 'pending-verification']);
        }

        $this->audit->record($user, 'payment-submit', 'Payment', $payment->id,
            "Submitted {$payment->code} (₦".number_format($payment->amount).') for verification');

        $this->notifications->push([
            'kind' => 'payment',
            'title' => 'New payment awaiting verification',
            'body' => "{$payment->code} — ₦".number_format($payment->amount).' submitted for review.',
            'forRole' => 'finance',
            'link' => '/payments/verification',
        ]);

        $recipients = Recipients::paymentApprovers($payment->propertyId);
        if ($recipients->isNotEmpty()) {
            Mail::to($recipients->all())->queue(new PaymentSubmittedMail([
                'code' => $payment->code,
                'amount' => $this->money($payment->amount),
                'tenantName' => Tenant::query()->whereKey($payment->tenantId)->value('name') ?? 'Unknown tenant',
                'propertyName' => Property::query()->whereKey($payment->propertyId)->value('name') ?? '—',
                'method' => $payment->method,
                'submittedAt' => $payment->submittedAt,
                'reviewUrl' => FrontendUrl::to('/payments/verification'),
            ]));
        }

        return $payment->refresh();
    }

    public function approve(User $user, string $id, ?string $notes = null): Payment
    {
        $payment = $this->locate($user, $id);
        $this->authorize($user, 'approve', $payment);
        $this->requireStatus($payment, 'pending-verification');

        return $this->settle($payment, $user, $notes);
    }

    /**
     * Apply an approved payment to its invoice and fire the approval
     * side-effects (audit, tenant notification + email). Idempotent — a second
     * call for an already-approved payment is a no-op. Shared by manual
     * verification and the payment-gateway success path.
     */
    public function settle(Payment $payment, ?User $actor = null, ?string $note = null): Payment
    {
        if ($payment->status === 'approved') {
            return $payment;
        }

        $payment->fill([
            'status' => 'approved',
            'reviewedById' => $actor?->id,
            'reviewedAt' => now()->toIso8601String(),
            'verificationNotes' => $note,
        ]);

        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()->find($payment->invoiceId);
        if ($invoice) {
            $invoice->amountPaid = min($invoice->total, $invoice->amountPaid + $payment->amount);
            $this->invoices->recompute($invoice);
            $payment->balanceAfter = max(0, $invoice->total - $invoice->amountPaid);
        }
        $payment->save();

        if ($actor) {
            $this->audit->record($actor, 'payment-approve', 'Payment', $payment->id,
                "Approved {$payment->code} (₦".number_format($payment->amount).')');
        }
        $this->notifications->push([
            'kind' => 'payment', 'title' => 'Payment approved',
            'body' => "Your payment {$payment->code} of ₦".number_format($payment->amount).' was approved.',
            'forUserId' => $payment->tenantId, 'link' => '/portal/payments',
        ]);

        $this->mailTenant($payment, new PaymentApprovedMail([
            'code' => $payment->code,
            'amount' => $this->money($payment->amount),
            'invoiceCode' => $invoice?->code ?? '—',
            'balanceAfter' => $this->money((int) ($payment->balanceAfter ?? 0)),
            'portalUrl' => FrontendUrl::to('/portal/payments'),
        ]));

        return $payment->refresh();
    }

    public function reject(User $user, string $id, string $reason): Payment
    {
        $payment = $this->locate($user, $id);
        $this->authorize($user, 'reject', $payment);
        $this->requireStatus($payment, 'pending-verification');

        $payment->update([
            'status' => 'rejected',
            'reviewedById' => $user->id,
            'reviewedAt' => now()->toIso8601String(),
            'rejectionReason' => $reason,
        ]);

        $invoice = Invoice::query()->find($payment->invoiceId);
        if ($invoice && $invoice->amountPaid === 0 && $invoice->status === 'pending-verification') {
            $this->invoices->recompute($invoice);
        }

        $this->audit->record($user, 'payment-reject', 'Payment', $payment->id, "Rejected {$payment->code}: {$reason}");
        $this->notifications->push([
            'kind' => 'payment', 'title' => 'Payment rejected',
            'body' => "Your payment {$payment->code} was rejected: {$reason}",
            'forUserId' => $payment->tenantId, 'link' => '/portal/payments',
        ]);

        $this->mailTenant($payment, new PaymentRejectedMail([
            'code' => $payment->code,
            'amount' => $this->money($payment->amount),
            'reason' => $reason,
            'portalUrl' => FrontendUrl::to('/portal/pay'),
        ]));

        return $payment->refresh();
    }

    public function requestInfo(User $user, string $id, string $note): Payment
    {
        $payment = $this->locate($user, $id);
        $this->authorize($user, 'approve', $payment);
        $payment->update(['verificationNotes' => $note]);

        $this->audit->record($user, 'edit', 'Payment', $payment->id, "Requested more info on {$payment->code}: {$note}");
        $this->notifications->push([
            'kind' => 'payment', 'title' => 'More information requested',
            'body' => "Regarding {$payment->code}: {$note}",
            'forUserId' => $payment->tenantId, 'link' => '/portal/payments',
        ]);

        $this->mailTenant($payment, new PaymentInfoRequestedMail([
            'code' => $payment->code,
            'note' => $note,
            'portalUrl' => FrontendUrl::to('/portal/payments'),
        ]));

        return $payment->refresh();
    }

    public function refund(User $user, string $id, string $reason): Payment
    {
        $payment = $this->locate($user, $id);
        $this->authorize($user, 'refund', $payment);
        $this->requireStatus($payment, 'approved');

        $payment->update(['status' => 'refunded']);

        $invoice = Invoice::query()->find($payment->invoiceId);
        if ($invoice) {
            $invoice->amountPaid = max(0, $invoice->amountPaid - $payment->amount);
            $this->invoices->recompute($invoice);
        }

        $this->audit->record($user, 'refund', 'Payment', $payment->id,
            "Refunded {$payment->code} (₦".number_format($payment->amount)."): {$reason}");
        $this->notifications->push([
            'kind' => 'payment', 'title' => 'Payment refunded',
            'body' => "Your payment {$payment->code} of ₦".number_format($payment->amount)." was refunded: {$reason}",
            'forUserId' => $payment->tenantId, 'link' => '/portal/payments',
        ]);

        return $payment->refresh();
    }

    private function requireStatus(Payment $payment, string $expected): void
    {
        abort_unless(
            $payment->status === $expected,
            422,
            "Payment {$payment->code} is '{$payment->status}'; this action needs '{$expected}'.",
        );
    }

    /**
     * Guard against duplicate attempts on the same invoice: refuse a new
     * payment while one is still `initiated` (online checkout in progress) or
     * `pending-verification` (receipt awaiting finance). A stale online
     * checkout (>20 min) is expired first so an abandoned tab can't block
     * forever.
     */
    public function assertNoOpenPayment(string $invoiceId): void
    {
        Payment::query()
            ->where('invoiceId', $invoiceId)
            ->where('status', 'initiated')
            ->where('submittedAt', '<', Carbon::now()->subMinutes(20)->toIso8601String())
            ->update([
                'status' => 'failed',
                'gatewayStatus' => 'expired',
                'rejectionReason' => 'Checkout was not completed in time.',
            ]);

        $open = Payment::query()
            ->where('invoiceId', $invoiceId)
            ->whereIn('status', ['initiated', 'pending-verification'])
            ->orderByDesc('submittedAt')
            ->first();

        if ($open) {
            $what = $open->status === 'initiated'
                ? 'an online checkout in progress'
                : 'a payment awaiting verification';
            abort(409, "This invoice already has {$what} ({$open->code}). Wait for it to complete or be rejected before starting another.");
        }
    }

    /**
     * Decode a base64 data URL into a stored file; returns the relative path. Only images and PDFs are
     * accepted, judged by what the bytes actually are (not what the upload claims), so a script or web
     * page can't be stored as a "receipt".
     */
    private function storeReceipt(string $paymentId, ?string $dataUrl): ?string
    {
        if (! $dataUrl) {
            return null;
        }

        $invalid = fn (string $why) => ValidationException::withMessages(['receiptDataUrl' => [$why]]);

        if (! preg_match('#^data:(?<mime>[\w/+.-]+);base64,(?<data>.+)$#s', $dataUrl, $m)) {
            throw $invalid('The receipt must be a base64 data URL.');
        }

        $binary = base64_decode($m['data'], true);
        if ($binary === false) {
            throw $invalid('The receipt file could not be read.');
        }
        if (strlen($binary) > 8 * 1024 * 1024) {
            throw $invalid('The receipt must be 8 MB or smaller.');
        }

        $ext = match (true) {
            str_starts_with($binary, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($binary, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($binary, 'RIFF') && substr($binary, 8, 4) === 'WEBP' => 'webp',
            str_starts_with($binary, '%PDF-') => 'pdf',
            default => null,
        };
        if ($ext === null) {
            throw $invalid('The receipt must be a PNG, JPEG, WebP or PDF file.');
        }

        $path = "receipts/{$paymentId}.{$ext}";
        Storage::disk('local')->put($path, $binary);

        return $path;
    }

    /** Queue a payment email to every login attached to the payment's tenant. */
    private function mailTenant(Payment $payment, Mailable $mail): void
    {
        $recipients = Recipients::tenantUsers($payment->tenantId);
        if ($recipients->isNotEmpty()) {
            Mail::to($recipients->all())->queue($mail);
        }
    }

    private function money(int $amount): string
    {
        return '₦'.number_format($amount);
    }
}

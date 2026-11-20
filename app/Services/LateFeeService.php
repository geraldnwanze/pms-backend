<?php

namespace App\Services;

use App\Models\AutoDebitAttempt;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Charges the late fee set in the organization settings (`lateFeePercent`, `gracePeriodDays`).
 *
 * Once an invoice is more than the grace period past its due date and still has a balance, it gets ONE late fee:
 * the set percentage of what is still owed at that moment. The fee joins the invoice's total (so it is paid, chased
 * and auto-debited like the rest) and shows as the invoice's late penalty. It is never charged again, never charged
 * while a payment is waiting to be verified, and a fee a person has waived is never put back.
 */
class LateFeeService
{
    /** Payments in these states are on their way: the tenant has acted, so no fee while they are resolved. */
    private const IN_FLIGHT = ['pending-verification', 'initiated'];

    public function __construct(private SettingsService $settings, private AuditService $audit, private NotificationService $notifications) {}

    /** @return array{applied:int,skipped:int,total:int,log:array<int,string>} */
    public function run(bool $dryRun = false, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $out = ['applied' => 0, 'skipped' => 0, 'total' => 0, 'log' => []];
        $policy = $this->policy();      // read once for the whole run, not once per invoice

        // Cheap filter first (unsettled and past due); the rest of the rules are checked per invoice.
        Invoice::query()->whereNotIn('status', ['cancelled', 'fully-paid'])->whereNotNull('dueDate')->where('dueDate', '<', $today->toDateString())
            ->whereNull('lateFeeAppliedOn')->whereNull('lateFeeWaivedOn')
            ->orderBy('id')->chunkById(200, function ($invoices) use ($today, $dryRun, $policy, &$out) {
                foreach ($invoices as $invoice) {
                    $fee = $this->feeFor($invoice, $today, $policy);

                    if ($fee === null) {
                        $out['skipped']++;

                        continue;
                    }

                    if ($dryRun) {
                        $out['applied']++;
                        $out['total'] += $fee;
                        $out['log'][] = "{$invoice->code}: would charge ₦".number_format($fee);

                        continue;
                    }

                    if ($this->apply($invoice->id, $today, $policy)) {
                        $out['applied']++;
                        $out['total'] += $fee;
                        $out['log'][] = "{$invoice->code}: charged ₦".number_format($fee);
                    } else {
                        $out['skipped']++;
                    }
                }
            });

        return $out;
    }

    /**
     * The late-fee policy from the organization settings.
     *
     * @return array{percent:float,grace:int}
     */
    public function policy(): array
    {
        $org = $this->settings->get('organization');

        return ['percent' => (float) ($org['lateFeePercent'] ?? 0), 'grace' => (int) ($org['gracePeriodDays'] ?? 0)];
    }

    /**
     * The fee this invoice would be charged as of $today, or null if it is not due one.
     *
     * @param  array{percent:float,grace:int}|null  $policy  pass the run's policy to avoid reading the settings again for every invoice
     */
    public function feeFor(Invoice $invoice, Carbon $today, ?array $policy = null): ?int
    {
        ['percent' => $percent, 'grace' => $grace] = $policy ?? $this->policy();

        if ($percent <= 0 || in_array($invoice->status, ['cancelled', 'fully-paid'], true) || ! $invoice->dueDate) {
            return null;
        }
        if ($invoice->lateFeeAppliedOn || $invoice->lateFeeWaivedOn || (int) $invoice->penalty > 0) {
            return null;        // already charged (or waived); an older invoice that already carries a penalty counts as charged
        }

        // "Past the grace period" means the grace days have fully gone by: due 1 Nov + 5 days → first charged on 7 Nov.
        if (! $today->gt(Carbon::parse($invoice->dueDate)->startOfDay()->addDays($grace))) {
            return null;
        }

        $owing = (int) $invoice->total - (int) $invoice->amountPaid;
        if ($owing <= 0 || $this->paymentInFlight($invoice->id)) {
            return null;
        }

        $fee = (int) round($owing * $percent / 100);

        return $fee > 0 ? $fee : null;
    }

    /** Charge the fee now (re-checked under a lock, so a parallel run or a payment landing at the same moment cannot double up). */
    public function apply(string $invoiceId, Carbon $today, ?array $policy = null): bool
    {
        $invoice = DB::transaction(function () use ($invoiceId, $today, $policy) {
            $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
            $fee = $invoice ? $this->feeFor($invoice, $today, $policy) : null;

            if ($fee === null) {
                return null;
            }

            $invoice->forceFill([
                'penalty' => $fee,
                'total' => InvoiceService::totalOf((int) collect($invoice->lineItems)->sum('amount'), $fee, (int) $invoice->discount),
                'lateFeeAppliedOn' => $today->toDateString(),
            ])->save();

            return $invoice;
        });

        if (! $invoice) {
            return false;
        }

        $this->audit->record('system', 'edit', 'Invoice', $invoice->id, 'Late fee of ₦'.number_format($invoice->penalty)." charged on {$invoice->code}");
        $this->notifications->push([
            'kind' => 'rent',
            'title' => 'Late fee charged',
            'body' => "Invoice {$invoice->code} was not paid in time, so a late fee of ₦".number_format($invoice->penalty).' has been added.',
            'forUserId' => $invoice->tenantId,
            'link' => "/portal/bills/{$invoice->id}",
        ]);

        return true;
    }

    private function paymentInFlight(string $invoiceId): bool
    {
        return Payment::query()->where('invoiceId', $invoiceId)->whereIn('status', self::IN_FLIGHT)->exists()
            || AutoDebitAttempt::query()->where('invoiceId', $invoiceId)->where('status', 'pending')->exists();
    }
}

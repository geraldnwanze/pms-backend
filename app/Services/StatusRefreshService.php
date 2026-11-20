<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use Illuminate\Support\Carbon;

/**
 * Statuses that depend on the calendar (an invoice going overdue, a lease running out) are
 * stored, so they go stale as days pass with nobody touching the record. This brings them
 * back in line. It only ever moves a record to the status the date implies; cancelled,
 * paid, terminated and already-expired records are left alone.
 */
class StatusRefreshService
{
    /** A lease ending within this many days is "expiring" (same window as the seeded data and the reminders). */
    public const EXPIRING_WINDOW_DAYS = 60;

    public function __construct(private InvoiceService $invoices, private UtilityBillingService $utilities) {}

    /** @return array{overdue:int,reopened:int,expired:int,expiring:int,reactivated:int,finalBills:int} */
    public function refresh(bool $dryRun = false, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        return [...$this->invoices($today, $dryRun), ...$this->leases($today, $dryRun)];
    }

    /** @return array{overdue:int,reopened:int} */
    private function invoices(Carbon $today, bool $dryRun): array
    {
        $out = ['overdue' => 0, 'reopened' => 0];

        Invoice::query()->whereIn('status', ['unpaid', 'overdue'])->chunkById(200, function ($chunk) use ($today, $dryRun, &$out) {
            foreach ($chunk as $invoice) {
                $want = $this->invoices->statusFor($invoice, $today);
                if ($want === $invoice->status) {
                    continue;
                }
                if ($want === 'overdue') {
                    $out['overdue']++;
                } elseif ($want === 'unpaid') {
                    $out['reopened']++;          // the due date was moved out again
                }
                if (! $dryRun) {
                    $invoice->forceFill(['status' => $want])->save();
                }
            }
        });

        return $out;
    }

    /** @return array{expired:int,expiring:int,reactivated:int,finalBills:int} */
    private function leases(Carbon $today, bool $dryRun): array
    {
        $out = ['expired' => 0, 'expiring' => 0, 'reactivated' => 0, 'finalBills' => 0];
        $window = $today->copy()->addDays(self::EXPIRING_WINDOW_DAYS);

        Lease::query()->whereIn('status', ['active', 'expiring', 'renewed'])->whereNotNull('endDate')
            ->chunkById(200, function ($chunk) use ($today, $window, $dryRun, &$out) {
                foreach ($chunk as $lease) {
                    $end = Carbon::parse($lease->endDate)->startOfDay();

                    if ($end->lessThan($today)) {
                        $want = 'expired';
                        $out['expired']++;
                    } elseif ($lease->status === 'active' && $end->lessThanOrEqualTo($window)) {
                        $want = 'expiring';
                        $out['expiring']++;
                    } elseif ($lease->status === 'expiring' && $end->greaterThan($window)) {
                        $want = 'active';           // the end date was pushed out
                        $out['reactivated']++;
                    } else {
                        continue;
                    }

                    if (! $dryRun) {
                        $lease->forceFill(['status' => $want])->save();

                        // A tenancy that has just run out: bill what is still unbilled on its unit to the tenant who is leaving.
                        if ($want === 'expired' && $this->utilities->billFinal('system', $lease->refresh(), $today)) {
                            $out['finalBills']++;
                        }
                    }
                }
            });

        return $out;
    }
}

<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Unit;
use App\Support\Dates;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues rent invoices on schedule, so nobody has to remember to.
 *
 * For every current lease, billing periods run from the lease's start date in steps of its billing cycle.
 * An invoice is issued once a period is two weeks away (or already under way) and is due on the period's
 * first day. The lease's rent and the unit's service charge are ANNUAL figures (that is how the lease screens,
 * the tenant portal and the lease form present them), so a period carries its share: a month is a twelfth, a
 * quarter a quarter. Naira are whole numbers, so shares are rounded cumulatively — twelve monthly invoices add
 * up to exactly the annual figure. A partial first or last period is prorated by days. Utilities are billed
 * from meter readings and are not part of this.
 *
 * Periods that ended before today are never back-billed, a period already invoiced (automatically or by
 * hand) is never billed again, and a period whose generated invoice was cancelled is not regenerated.
 */
class InvoiceGenerationService
{
    /** Months covered by each billing cycle. `custom` has no regular rhythm, so it is left to people. */
    public const CYCLE_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'half-yearly' => 6, 'annual' => 12];

    /** Invoice this many days before the period starts. */
    public const LEAD_DAYS = 14;

    /** A hand-made invoice due this close to (or after) a period's start counts as that period's invoice. */
    private const MANUAL_MATCH_DAYS = 7;

    public function __construct(private AuditService $audit, private NotificationService $notifications) {}

    /** @return array{created:int,skipped:int,unbillable:int,log:array<int,string>} */
    public function run(bool $dryRun = false, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $out = ['created' => 0, 'skipped' => 0, 'unbillable' => 0, 'log' => []];

        Lease::query()->whereIn('status', LeaseService::CURRENT)->orderBy('id')->chunkById(200, function ($leases) use ($today, $dryRun, &$out) {
            foreach ($leases as $lease) {
                $periods = $this->periodsDue($lease, $today);

                if ($periods === null) {
                    $out['unbillable']++;

                    continue;
                }

                foreach ($periods as $period) {
                    if ($this->alreadyInvoiced($lease, $period)) {
                        $out['skipped']++;

                        continue;
                    }

                    if ($dryRun) {
                        $out['created']++;
                        $out['log'][] = "{$lease->code}: would invoice {$period['start']->toDateString()} – {$period['end']->toDateString()}";

                        continue;
                    }

                    $invoice = $this->issue($lease, $period, $today);
                    $invoice ? $out['created']++ : $out['skipped']++;
                    $invoice && $out['log'][] = "{$lease->code}: {$invoice->code} for {$period['start']->toDateString()} – {$period['end']->toDateString()}";
                }
            }
        });

        return $out;
    }

    /**
     * The billing periods of this lease that should be invoiced as of $today: those whose invoicing window has
     * opened and that have not yet ended. Null when the lease cannot be billed automatically at all.
     *
     * @return array<int,array{index:int,start:Carbon,end:Carbon,months:int,partial:bool,fraction:float}>|null
     */
    public function periodsDue(Lease $lease, Carbon $today): ?array
    {
        $months = self::CYCLE_MONTHS[$lease->billingCycle] ?? null;

        if (! $months || ! $lease->tenantId || (int) $lease->rent <= 0 || ! $lease->startDate || ! $lease->endDate) {
            return null;
        }

        $leaseStart = Carbon::parse($lease->startDate)->startOfDay();
        $leaseEnd = Carbon::parse($lease->endDate)->startOfDay();
        $periods = [];

        for ($k = 0; ; $k++) {
            // Always counted from the lease's start date, so a 31st doesn't drift to the 28th for good.
            $start = $leaseStart->copy()->addMonthsNoOverflow($k * $months);

            // A period that would begin on the lease's end date is not billed: the app's own default ends a lease on
            // its anniversary (12 Nov to 12 Nov), which must not leave a one-day stub invoice at the end.
            if ($start->gte($leaseEnd) || $start->copy()->subDays(self::LEAD_DAYS)->gt($today)) {
                break;
            }

            $next = $leaseStart->copy()->addMonthsNoOverflow(($k + 1) * $months);
            $end = $next->copy()->subDay();
            $partial = $end->gt($leaseEnd);
            $end = $partial ? $leaseEnd->copy() : $end;

            if ($end->lt($today)) {
                continue;       // already over: never back-billed
            }

            $periods[] = [
                'index' => $k, 'start' => $start, 'end' => $end, 'months' => $months, 'partial' => $partial,
                'fraction' => $partial ? ($start->diffInDays($end) + 1) / max(1, $start->diffInDays($next)) : 1.0,
            ];
        }

        return $periods;
    }

    private function alreadyInvoiced(Lease $lease, array $period): bool
    {
        $start = $period['start']->toDateString();

        $generated = Invoice::query()->where('leaseId', $lease->id)->where('periodStart', $start)->exists();
        if ($generated) {
            return true;
        }

        // A hand-made invoice due around then covers the period, but only if it bills rent (a utility or fee invoice does not).
        return Invoice::query()->where('leaseId', $lease->id)->whereNull('periodStart')->where('status', '!=', 'cancelled')
            ->where('dueDate', '>=', $period['start']->copy()->subDays(self::MANUAL_MATCH_DAYS)->toDateString())
            ->where('dueDate', '<=', $period['end']->toDateString())
            ->get(['lineItems'])
            ->contains(fn (Invoice $i) => collect($i->lineItems)->contains(fn ($li) => ($li['type'] ?? null) === 'rent'));
    }

    /**
     * This period's share of an annual amount. Full periods are cut from the running total (so a year of monthly
     * shares sums exactly to the annual figure); a part-period is the proportion of its days.
     */
    private function shareOf(int $annual, array $period): int
    {
        if ($period['partial']) {
            return (int) round($annual * $period['months'] / 12 * $period['fraction']);
        }

        $before = $period['index'] * $period['months'];

        return (int) round($annual * ($before + $period['months']) / 12) - (int) round($annual * $before / 12);
    }

    /** Create the invoice for one period. Returns null if the period turns out to be invoiced already. */
    public function issue(Lease $lease, array $period, Carbon $today): ?Invoice
    {
        $unit = Unit::query()->find($lease->unitId);
        if (! $unit) {
            return null;
        }

        $label = $period['start']->format('j M Y').' to '.$period['end']->format('j M Y');
        $rent = $this->shareOf((int) $lease->rent, $period);
        $service = $this->shareOf((int) $unit->serviceCharge, $period);

        $items = [['id' => 'li_'.Str::lower(Str::random(10)), 'description' => "Rent, {$label}", 'type' => 'rent', 'amount' => $rent]];
        if ($service > 0) {
            $items[] = ['id' => 'li_'.Str::lower(Str::random(10)), 'description' => 'Service charge', 'type' => 'service-charge', 'amount' => $service];
        }

        $id = 'inv_'.Str::lower(Str::random(12));

        try {
            // Its own (nested) transaction: on Postgres a refused duplicate would otherwise poison any enclosing one.
            $invoice = DB::transaction(fn () => Invoice::query()->create([
                'id' => $id, 'code' => 'INV-'.Str::upper(substr($id, -5)),
                'leaseId' => $lease->id, 'unitId' => $lease->unitId, 'propertyId' => $lease->propertyId, 'tenantId' => $lease->tenantId,
                'issueDate' => $today->toDateString(), 'dueDate' => $period['start']->max($today)->toDateString(),
                'lineItems' => $items, 'total' => $rent + $service, 'amountPaid' => 0, 'status' => 'unpaid', 'cycle' => $lease->billingCycle,
                'periodStart' => $period['start']->toDateString(), 'periodEnd' => $period['end']->toDateString(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;        // another run got there first
        }

        $this->audit->record('system', 'create', 'Invoice', $invoice->id, "Invoiced {$lease->code} automatically ({$label})");
        $this->notifications->push([
            'kind' => 'rent',
            'title' => 'New invoice issued',
            'body' => "Invoice {$invoice->code} for ₦".number_format($invoice->total).' is due '.Dates::friendly($invoice->dueDate).'.',
            'forUserId' => $invoice->tenantId,
            'link' => "/portal/bills/{$invoice->id}",
        ]);

        return $invoice;
    }
}

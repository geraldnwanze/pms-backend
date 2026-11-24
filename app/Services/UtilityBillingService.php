<?php

namespace App\Services;

use App\Mail\UtilityBillMail;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityBillRun;
use App\Support\Dates;
use App\Support\FrontendUrl;
use App\Support\Modules;
use App\Support\Recipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns meter readings into invoices. A meter is billed from the reading it was last billed up to (`billedReading`)
 * to its latest reading, at its rate; all of a unit's meters go on one invoice to the tenant who holds the unit now.
 * Billing moves the marker in the same transaction as the invoice is written, so a reading is billed exactly once.
 *
 * "The tenant who holds the unit" is the current lease; and for FINAL_BILL_DAYS after a tenancy ends it is still that tenant —
 * their last use is billed to them, at once when the lease is terminated or runs out (see billFinal) and, for readings taken
 * after they left, by the usual billing. After that window a vacant unit's use is the landlord's.
 */
class UtilityBillingService
{
    /** Days a tenant has to pay a utility invoice. */
    public const DUE_DAYS = 14;

    /** How long after a tenancy ends its last tenant is still the one billed for the unit's use (readings taken at move-out). */
    public const FINAL_BILL_DAYS = 60;

    /** Who to email for each tenant, looked up once for a bulk run (null otherwise: each bill looks up its own tenant). */
    private ?Collection $recipients = null;

    public function __construct(private AuditService $audit, private NotificationService $notifications) {}

    /**
     * Bill every meter that has unbilled use (the monthly job).
     *
     * @return array{invoices:int,meters:int,total:int,skipped:array<int,string>,log:array<int,string>}
     */
    public function run(bool $dryRun = false, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $out = ['invoices' => 0, 'meters' => 0, 'total' => 0, 'skipped' => [], 'log' => []];

        Meter::query()->whereNotNull('unitId')->whereNotNull('currentReading')->orderBy('id')->get()
            ->groupBy('unitId')->each(function ($meters, $unitId) use ($dryRun, $today, &$out) {
                if ($meters->every(fn (Meter $m) => $m->unbilledUnits <= 0 && $m->currentReading >= (float) $m->billedUpTo())) {
                    return;
                }

                $r = $this->billUnit((string) $unitId, $meters->pluck('id')->all(), 'system', $today, strict: false, dryRun: $dryRun);

                $out['invoices'] += $r['invoice'] ? 1 : 0;
                $out['meters'] += $r['meters'];
                $out['total'] += $r['total'];
                $out['skipped'] = array_merge($out['skipped'], $r['skipped']);
                if ($r['invoice'] || $r['wouldBill']) {
                    $out['log'][] = "Unit {$unitId}: ".($dryRun ? 'would bill' : 'billed').' ₦'.number_format($r['total']);
                }
            });

        return $out;
    }

    /**
     * Bill everything that is ready, for the properties this person manages (staff action; `$dryRun` only reports).
     * One invoice per unit, as in the monthly job, but a unit with nobody living in it is left alone and reported:
     * unlike the job, a person clicking a button should not quietly write off a vacant unit's use.
     *
     * @param  array<int,string>|null  $propertyIds  narrow to these properties; null means all they can bill
     * @return array{dryRun:bool,invoices:int,meters:int,total:int,units:array<int,array<string,mixed>>,skipped:array<int,array{unitId:string,unitCode:?string,propertyId:string,propertyName:?string,reason:string}>}
     */
    public function billAll(User $user, ?array $propertyIds = null, bool $dryRun = false, ?Carbon $today = null): array
    {
        abort_unless(app(MeterService::class)->mayAccessCollection($user, 'manage'), 403, 'You cannot bill meters.');

        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $out = ['dryRun' => $dryRun, 'invoices' => 0, 'meters' => 0, 'total' => 0, 'units' => [], 'skipped' => []];

        $byUnit = app(MeterService::class)->billableBy($user, $propertyIds)->groupBy('unitId');
        $unitCodes = Unit::query()->whereIn('id', $byUnit->keys())->pluck('code', 'id');
        // Unit codes repeat across properties ("A06" is in several), so every line says which property it is in.
        $propertyNames = Property::query()->whereIn('id', $byUnit->map(fn ($m) => $m->first()->propertyId)->unique()->values())->pluck('name', 'id');
        $skip = function (string $unitId, string $reason) use (&$out, $unitCodes, $propertyNames, $byUnit) {
            $propertyId = $byUnit[$unitId]->first()->propertyId;
            $out['skipped'][] = ['unitId' => $unitId, 'unitCode' => $unitCodes[$unitId] ?? null, 'propertyId' => $propertyId, 'propertyName' => $propertyNames[$propertyId] ?? null, 'reason' => $reason];
        };

        // Who holds each unit, and their names, looked up once for the whole run rather than once per unit.
        $holders = $this->holdersOf($byUnit->keys()->map(fn ($k) => (string) $k)->all(), $today);
        $tenantNames = Tenant::query()->whereIn('id', $holders->pluck('tenantId')->unique()->values())->pluck('name', 'id');
        $this->recipients = $dryRun ? null : Recipients::tenantUsersByTenant($holders->pluck('tenantId')->all());

        foreach ($byUnit as $unitId => $meters) {
            $unitId = (string) $unitId;
            if ($meters->every(fn (Meter $m) => $m->unbilledUnits <= 0 && $m->currentReading >= (float) $m->billedUpTo())) {
                continue;
            }
            if (! ($holder = $holders->get($unitId))) {
                $skip($unitId, 'No tenant at the moment, so there is nobody to bill.');

                continue;
            }

            try {
                $r = $this->billUnit($unitId, $meters->pluck('id')->all(), $user, $today, strict: false, dryRun: $dryRun, holder: $holder);
            } catch (Throwable $e) {
                // The unit's own transaction rolled back, so nothing of it was billed and its meters are untouched: say so
                // and carry on with the rest, so one bad unit neither loses the run nor hides what was billed.
                report($e);
                $skip($unitId, 'Could not be billed because of an error. Nothing was billed for this unit; try again.');

                continue;
            }

            foreach ($r['skipped'] as $reason) {
                $skip($unitId, $reason);
            }
            if (! $r['invoice'] && ! $r['wouldBill']) {
                continue;
            }

            $lease = $r['lease'];
            $names = $meters->keyBy('id');
            $out['invoices'] += $r['invoice'] ? 1 : 0;
            $out['meters'] += $r['meters'];
            $out['total'] += $r['total'];
            $out['units'][] = [
                'unitId' => $unitId, 'unitCode' => $unitCodes[$unitId] ?? null,
                'propertyId' => $meters->first()->propertyId, 'propertyName' => $propertyNames[$meters->first()->propertyId] ?? null,
                'tenantId' => $lease?->tenantId, 'tenantName' => $lease ? ($tenantNames[$lease->tenantId] ?? null) : null,
                'leaseCode' => $lease?->code,
                'final' => $r['final'], 'invoiceId' => $r['invoice']?->id, 'invoiceCode' => $r['invoice']?->code,
                'total' => $r['total'],
                'meters' => array_map(fn ($i) => [
                    'meterId' => $i['meterId'], 'meterNumber' => $names[$i['meterId']]->meterNumber ?? null, 'type' => $names[$i['meterId']]->type ?? null,
                    'unitOfMeasure' => $names[$i['meterId']]->unitOfMeasure ?? null,
                    'from' => $i['fromReading'], 'to' => $i['toReading'], 'units' => $i['units'], 'rate' => $i['rate'], 'amount' => $i['amount'],
                ], $r['items']),
            ];
        }

        $this->recipients = null;

        if (! $dryRun) {
            $this->recordBulkRun($user, $out, $propertyNames);
        }

        return $out;
    }

    /**
     * The latest bulk runs on the properties this person can see, newest first, for the people who may bill.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recentRuns(User $user, int $limit = 10): array
    {
        abort_unless(app(MeterService::class)->mayAccessCollection($user, 'manage'), 403, 'You cannot bill meters.');

        $visible = app(PropertyScope::class)->visibleIds($user);
        $runs = UtilityBillRun::query()
            ->when($visible !== null, fn ($q) => $q->whereIn('propertyId', $visible))
            ->orderByDesc('createdAt')->orderByDesc('id')->limit(max(1, min($limit, 50)))->get();

        $properties = Property::query()->whereIn('id', $runs->pluck('propertyId')->unique())->pluck('name', 'id');
        $users = User::query()->whereIn('id', $runs->pluck('userId')->unique())->pluck('name', 'id');

        return $runs->map(fn (UtilityBillRun $r) => [
            'id' => $r->id, 'propertyId' => $r->propertyId, 'propertyName' => $properties[$r->propertyId] ?? null,
            'userId' => $r->userId, 'userName' => $users[$r->userId] ?? null,
            'invoices' => $r->invoices, 'total' => $r->total, 'unitsSkipped' => $r->unitsSkipped, 'createdAt' => $r->createdAt,
        ])->all();
    }

    /**
     * One line in the audit trail per property a bulk run billed, so "who billed everything, when, for how much" is
     * a single search ("Bulk-billed") instead of piecing it together from every invoice. Filed under the property,
     * so the people who run it see it. A run that billed nothing leaves no trace.
     *
     * @param  array<string,mixed>  $out
     * @param  Collection<string,string>  $propertyNames
     */
    private function recordBulkRun(User $user, array $out, $propertyNames): void
    {
        $skipped = collect($out['skipped'])->groupBy('propertyId');

        collect($out['units'])->groupBy('propertyId')->each(function ($units, $propertyId) use ($user, $propertyNames, $skipped) {
            $invoices = $units->filter(fn ($u) => $u['invoiceId'])->count();
            if ($invoices === 0) {
                return;
            }

            $unitsSkipped = $skipped->get($propertyId, collect())->pluck('unitId')->unique()->count();
            // The invoices exist whatever happens to their history, so a failure to write it is logged, not thrown.
            $this->bestEffort(fn () => UtilityBillRun::query()->create([
                'id' => 'ubr_'.Str::lower(Str::random(12)), 'propertyId' => $propertyId, 'userId' => $user->id,
                'invoices' => $invoices, 'total' => (int) $units->sum('total'), 'unitsSkipped' => $unitsSkipped, 'createdAt' => now()->toIso8601String(),
            ]));
            $this->bestEffort(fn () => $this->audit->record(
                $user, 'create', 'Property', (string) $propertyId,
                "Bulk-billed utilities: {$invoices} invoice".($invoices === 1 ? '' : 's').' (₦'.number_format((int) $units->sum('total')).') on '.($propertyNames[$propertyId] ?? $propertyId)
                .($unitsSkipped ? "; {$unitsSkipped} unit".($unitsSkipped === 1 ? '' : 's').' skipped' : ''),
            ));
        });
    }

    /** Bill one meter now (staff action): the tenant of its unit is invoiced for what has been read since the last bill. */
    public function billMeter(User $user, string $meterId): Invoice
    {
        $meter = Meter::query()->findOrFail($meterId);
        abort_unless($meter->unitId, 422, 'This meter is not attached to a unit, so there is no tenant to bill.');
        app(MeterService::class)->assertMayBill($user, $meter);

        $r = $this->billUnit($meter->unitId, [$meter->id], $user, Carbon::today(), strict: true);

        abort_unless($r['invoice'], 422, $r['skipped'][0] ?? 'Nothing to bill on this meter.');

        return $r['invoice'];
    }

    /**
     * What billing one meter would raise today, or why it could not be billed, without billing anything. `$reading` asks it
     * as if the meter had just been read at that value (the reading dialog's "log this reading and bill"). The answer uses the
     * same rules billUnit applies, so what staff are shown is what the invoice will say.
     *
     * @return array<string,mixed> `ok: true` with the invoice's figures, or `ok: false` with a `reason`
     */
    public function previewMeter(User $user, string $meterId, ?float $reading = null, ?Carbon $today = null): array
    {
        $meter = Meter::query()->findOrFail($meterId);
        app(MeterService::class)->assertMayBill($user, $meter);
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        if (! $meter->unitId) {
            return ['ok' => false, 'reason' => 'This meter is not attached to a unit, so there is no tenant to bill.'];
        }
        if ($reading !== null && $reading < (float) $meter->currentReading) {
            return ['ok' => false, 'reason' => "The reading ({$reading}) is lower than the current reading ({$meter->currentReading})."];
        }

        $from = (float) $meter->billedUpTo();
        $to = (float) ($reading ?? $meter->currentReading);
        if ($to < $from) {
            return ['ok' => false, 'reason' => "The reading ({$to}) is lower than what was last billed ({$from}); check the meter."];
        }
        if (abs($to - $from) < 0.0005) {
            return ['ok' => false, 'reason' => 'Nothing to bill: no use has been read since the last bill.'];
        }
        if ((float) $meter->ratePerUnit <= 0) {
            return ['ok' => false, 'reason' => 'No rate is set, so its use cannot be priced.'];
        }

        $lease = $this->responsibleLease($meter->unitId, $today);
        if (! $lease) {
            return ['ok' => false, 'reason' => 'This unit has no tenant at the moment, so there is nobody to bill.'];
        }

        $units = round($to - $from, 3);
        $final = ! $lease->holdsUnitOn($today);

        return [
            'ok' => true,
            'tenantName' => Tenant::query()->whereKey($lease->tenantId)->value('name') ?? 'the tenant',
            'leaseCode' => $lease->code,
            'issueDate' => $today->toDateString(),
            'dueDate' => $today->copy()->addDays(self::DUE_DAYS)->toDateString(),
            'from' => $from, 'to' => $to, 'units' => $units, 'rate' => (float) $meter->ratePerUnit,
            'total' => (int) round($units * (float) $meter->ratePerUnit),
            'final' => $final,
            'endedOn' => $final ? $lease->endedOn($today) : null,
        ];
    }

    /**
     * @param  User|'system'  $actor
     * @param  array<int,string>  $meterIds
     * @param  bool  $strict  a manual request must say why nothing was billed; the job quietly moves the marker on a vacant unit
     * @param  Lease|null  $holder  who holds the unit today, if the caller already knows (a bulk run looks them all up at once)
     * @return array{invoice:?Invoice,meters:int,total:int,skipped:array<int,string>,wouldBill:bool,lease:?Lease,items:array<int,array<string,mixed>>}
     */
    public function billUnit(string $unitId, array $meterIds, User|string $actor, Carbon $today, bool $strict, bool $dryRun = false, ?Lease $holder = null): array
    {
        $result = ['invoice' => null, 'meters' => 0, 'total' => 0, 'skipped' => [], 'wouldBill' => false, 'lease' => null, 'final' => false, 'items' => []];

        $invoice = DB::transaction(function () use ($unitId, $meterIds, $today, $strict, $dryRun, $holder, &$result) {
            // Locked so two runs (or a run and a click) cannot bill the same reading twice.
            $meters = Meter::query()->whereIn('id', $meterIds)->where('unitId', $unitId)->orderBy('id')->lockForUpdate()->get();
            $lease = $holder ?? $this->responsibleLease($unitId, $today);

            $items = [];
            $billed = [];
            foreach ($meters as $meter) {
                $from = (float) $meter->billedUpTo();
                $to = $meter->currentReading;

                if ($to === null || abs($to - $from) < 0.0005) {
                    continue;
                }
                if ($to < $from) {
                    $result['skipped'][] = "{$meter->meterNumber}: the reading ({$to}) is lower than what was last billed ({$from}); check the meter.";

                    continue;
                }
                if ((float) $meter->ratePerUnit <= 0) {
                    $result['skipped'][] = "{$meter->meterNumber}: no rate is set, so its use cannot be priced.";

                    continue;
                }

                $units = round($to - $from, 3);
                $amount = (int) round($units * (float) $meter->ratePerUnit);
                $billed[] = [$meter, $to];
                $items[] = [
                    'id' => 'li_'.Str::lower(Str::random(10)),
                    'description' => ucfirst($meter->type)." {$meter->meterNumber}: {$units} {$meter->unitOfMeasure} × ₦".number_format((float) $meter->ratePerUnit, 2),
                    'type' => 'utility', 'amount' => $amount,
                    'meterId' => $meter->id, 'fromReading' => $from, 'toReading' => $to, 'units' => $units, 'rate' => (float) $meter->ratePerUnit,
                ];
            }

            if (! $billed) {
                return null;
            }

            if (! $lease) {
                abort_if($strict, 422, 'This unit has no tenant at the moment, so there is nobody to bill.');
                // Nobody to bill: the use is the landlord's, so the marker moves on and it is not billed to the next tenant.
                if (! $dryRun) {
                    foreach ($billed as [$meter, $to]) {
                        $meter->forceFill(['billedReading' => $to, 'billedAt' => $today->toDateString()])->save();
                    }
                }
                $result['skipped'][] = "Unit {$unitId} has no tenant; its use was not billed.";

                return null;
            }

            $total = (int) collect($items)->sum('amount');
            $result['meters'] = count($billed);
            $result['total'] = $total;
            $result['wouldBill'] = true;
            $result['lease'] = $lease;
            $result['final'] = ! $lease->holdsUnitOn($today);          // the tenancy is over: this is their last bill
            $result['items'] = $items;

            if ($dryRun) {
                return null;
            }

            $id = 'inv_'.Str::lower(Str::random(12));
            $invoice = Invoice::query()->create([
                'id' => $id, 'code' => 'INV-'.Str::upper(substr($id, -5)),
                'leaseId' => $lease->id, 'unitId' => $unitId, 'propertyId' => $lease->propertyId, 'tenantId' => $lease->tenantId,
                'issueDate' => $today->toDateString(), 'dueDate' => $today->copy()->addDays(self::DUE_DAYS)->toDateString(),
                'lineItems' => $items, 'total' => $total, 'amountPaid' => 0, 'status' => 'unpaid', 'cycle' => 'custom',
            ]);

            foreach ($billed as [$meter, $to]) {
                $meter->forceFill(['billedReading' => $to, 'billedAt' => $today->toDateString()])->save();
            }

            return $invoice;
        });

        if (! $invoice) {
            return $result;
        }

        $result['invoice'] = $invoice;

        // The invoice is committed and the meters' markers have moved. The record and the notice are best-effort from
        // here: if one fails (a broadcaster that is down, say) it is logged, not thrown, so nobody is told "billing
        // failed" about an invoice that exists — and a bulk run does not stop with the rest of its units unbilled.
        $final = $result['final'];
        $this->bestEffort(fn () => $this->audit->record($actor, 'create', 'Invoice', $invoice->id, "Billed utilities on {$invoice->code} (₦".number_format($invoice->total).')'.($final ? ", final bill after lease {$result['lease']->code} ended" : '')));
        $this->bestEffort(fn () => $this->notifications->push([
            'kind' => 'rent',
            'title' => $final ? 'Final utility bill' : 'Utility bill issued',
            'body' => $final
                ? "Your tenancy has ended. Invoice {$invoice->code} for ₦".number_format($invoice->total).' covers your last utility use and is due '.Dates::friendly($invoice->dueDate).'.'
                : "Invoice {$invoice->code} for ₦".number_format($invoice->total).' (utilities) is due '.Dates::friendly($invoice->dueDate).'.',
            'forUserId' => $invoice->tenantId,
            'link' => "/portal/bills/{$invoice->id}",
        ]));
        $this->bestEffort(fn () => $this->emailBill($invoice, $final));

        return $result;
    }

    /** Tell the tenant by email, where they have a login: what is billed, when it is due, and a link straight to the bill. */
    private function emailBill(Invoice $invoice, bool $final): void
    {
        $recipients = $this->recipients?->get($invoice->tenantId) ?? Recipients::tenantUsers($invoice->tenantId);
        if ($recipients->isEmpty()) {
            return;
        }

        Mail::to($recipients->all())->queue(new UtilityBillMail([
            'code' => $invoice->code,
            'dueDate' => Carbon::parse($invoice->dueDate)->toFormattedDateString(),
            'total' => '₦'.number_format($invoice->total),
            'final' => $final,
            'lines' => collect($invoice->lineItems)->map(fn ($li) => [
                'description' => (string) preg_replace('/ × ₦[\d,.]+$/u', '', (string) $li['description']),
                'amount' => '₦'.number_format($li['amount']),
            ])->all(),
            'portalUrl' => FrontendUrl::to("/portal/bills/{$invoice->id}"),
        ]));
    }

    /**
     * Who is billed for each of these units on this day, keyed by unit id: whoever holds it, else the tenant whose tenancy
     * ended within FINAL_BILL_DAYS. Units with neither are left out. (The same answer responsibleLease() gives, in two queries.)
     *
     * @param  array<int,string>  $unitIds
     * @return Collection<string,Lease>
     */
    private function holdersOf(array $unitIds, Carbon $today)
    {
        $holding = Lease::query()->whereIn('unitId', $unitIds)->whereIn('status', LeaseService::CURRENT)
            ->where('startDate', '<=', $today->toDateString())->where('endDate', '>=', $today->toDateString())
            ->orderByDesc('startDate')->get()->groupBy('unitId')->map->first();

        $vacant = array_values(array_diff($unitIds, $holding->keys()->all()));
        if (! $vacant) {
            return $holding;
        }

        $justLeft = Lease::query()->whereIn('unitId', $vacant)->get()
            ->filter(fn (Lease $l) => $this->endedRecently($l, $today))
            ->sortByDesc(fn (Lease $l) => $l->endedOn($today))->groupBy('unitId')->map->first();

        return $holding->union($justLeft);
    }

    /** The tenant billed for a unit on this day: whoever holds it, else the one who left within FINAL_BILL_DAYS. */
    public function responsibleLease(string $unitId, Carbon $today): ?Lease
    {
        return $this->currentLease($unitId, $today)
            ?? Lease::query()->where('unitId', $unitId)->get()
                ->filter(fn (Lease $l) => $this->endedRecently($l, $today))
                ->sortByDesc(fn (Lease $l) => $l->endedOn($today))->first();
    }

    private function endedRecently(Lease $lease, Carbon $today): bool
    {
        $ended = $lease->endedOn($today);

        return $ended !== null && $ended <= $today->toDateString()
            && Carbon::parse($ended)->startOfDay()->addDays(self::FINAL_BILL_DAYS)->greaterThanOrEqualTo($today);
    }

    /**
     * Bill the use still unbilled on an ended lease's unit to the tenant who is leaving: their final bill. It is called when
     * a lease is terminated or runs out, and never throws — ending a tenancy must not fail because billing could not run.
     * Nothing is billed if someone else already holds the unit (their use and the leaver's cannot be told apart) or if the
     * tenancy is not over.
     */
    public function billFinal(User|string $actor, Lease $lease, ?Carbon $today = null): ?Invoice
    {
        // With the module off nothing is billed. The use stays unbilled and can still be billed within the final-bill window once it is back on.
        if (! app(Modules::class)->enabled('utilities')) {
            return null;
        }

        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        try {
            if ($this->responsibleLease($lease->unitId, $today)?->id !== $lease->id || $lease->holdsUnitOn($today)) {
                return null;
            }

            $meterIds = Meter::query()->where('unitId', $lease->unitId)->whereNotNull('currentReading')->pluck('id')->all();

            return $meterIds ? $this->billUnit($lease->unitId, $meterIds, $actor, $today, strict: false, holder: $lease)['invoice'] : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Whoever holds the unit on this day. */
    private function currentLease(string $unitId, Carbon $today): ?Lease
    {
        return Lease::query()->where('unitId', $unitId)->whereIn('status', LeaseService::CURRENT)
            ->where('startDate', '<=', $today->toDateString())->where('endDate', '>=', $today->toDateString())
            ->orderByDesc('startDate')->first();
    }

    /** Run something that must not be able to undo or hide work already done: if it fails, log it and carry on. */
    private function bestEffort(callable $do): void
    {
        try {
            $do();
        } catch (Throwable $e) {
            report($e);
        }
    }
}

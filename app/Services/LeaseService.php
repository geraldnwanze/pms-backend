<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LeaseService extends CrudService
{
    protected string $model = Lease::class;

    protected array $searchable = ['code', 'notes'];

    protected array $filterable = ['propertyId', 'unitId', 'tenantId', 'status', 'billingCycle'];

    protected array $sortable = ['id', 'code', 'startDate', 'endDate', 'rent', 'status'];

    protected string $defaultSort = '-startDate';

    protected string $idPrefix = 'lease';

    protected ?string $codeColumn = 'code';

    protected string $codePrefix = 'LSE';

    protected array $createDefaults = ['status' => 'active', 'noticePeriodDays' => 90, 'signed' => false];

    public function __construct(
        PropertyScope $scope,
        private AuditService $audit,
        private NotificationService $notifications,
    ) {
        parent::__construct($scope);
    }

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $this->scopeByProperty($query, $user);
    }

    /** Statuses of a lease that still holds its unit. */
    public const CURRENT = ['active', 'expiring', 'renewed'];

    /**
     * A unit can only be let to one person at a time: no other current lease on it may overlap these dates.
     * (A lease that starts the day another ends is fine — that is how a renewal-as-new-lease looks.)
     */
    private function assertNoOverlap(string $unitId, ?string $start, ?string $end, ?string $ignoreId = null): void
    {
        if (! $start || ! $end) {
            return;
        }

        $from = Carbon::parse($start)->startOfDay();
        $to = Carbon::parse($end)->startOfDay();

        $clash = Lease::query()->where('unitId', $unitId)->whereIn('status', self::CURRENT)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get(['id', 'code', 'startDate', 'endDate'])
            ->first(fn (Lease $other) => $other->startDate && $other->endDate
                && Carbon::parse($other->startDate)->startOfDay()->lt($to) && Carbon::parse($other->endDate)->startOfDay()->gt($from));

        if ($clash) {
            abort(422, "That unit is already let under {$clash->code} ({$clash->startDate} to {$clash->endDate}).");
        }
    }

    private function holdsUnit(string $unitId, string $exceptLeaseId): bool
    {
        return Lease::query()->where('unitId', $unitId)->where('id', '!=', $exceptLeaseId)->whereIn('status', self::CURRENT)->exists();
    }

    protected function prepareForCreate(User $user, array $data): array
    {
        // A lease is for a unit on the stated property — never a unit from someone else's portfolio.
        abort_unless(
            Unit::query()->whereKey($data['unitId'] ?? null)->where('propertyId', $data['propertyId'] ?? null)->exists(),
            422,
            'That unit does not belong to the lease\'s property.',
        );

        // A lease starts active. Renewing, terminating and expiring have their own actions (which also
        // look after the unit), so a client can't create one that is already over.
        abort_unless(($data['status'] ?? 'active') === 'active', 422, 'A new lease must be active.');

        $this->assertNoOverlap($data['unitId'], $data['startDate'] ?? null, $data['endDate'] ?? null);

        return parent::prepareForCreate($user, $data);
    }

    protected function prepareForUpdate(User $user, Model $record, array $data): array
    {
        abort_if(
            array_key_exists('status', $data) && $data['status'] !== $record->status,
            422,
            'Renew or terminate a lease through its own action; expiry happens automatically.',
        );
        unset($data['status']);

        abort_if(array_key_exists('unitId', $data) && $data['unitId'] !== $record->unitId, 422, 'A lease cannot be moved to a different unit.');

        // The deposit asked for cannot drop below what has already been taken — that money would have no claim behind it.
        if (array_key_exists('securityDeposit', $data)) {
            abort_if((int) $data['securityDeposit'] < DepositService::receivedOf($record->depositLedger), 422, 'The deposit cannot be set below the amount already received (₦'.number_format(DepositService::receivedOf($record->depositLedger)).').');
        }

        if (in_array($record->status, self::CURRENT, true) && (array_key_exists('startDate', $data) || array_key_exists('endDate', $data))) {
            $this->assertNoOverlap($record->unitId, $data['startDate'] ?? $record->startDate, $data['endDate'] ?? $record->endDate, $record->id);
        }

        return $data;
    }

    protected function afterCreate(User $user, Model $record, array $data): void
    {
        // Letting a unit takes it off the market. (A unit that is under repair or renovation keeps that status.)
        Unit::query()->where('id', $record->unitId)->whereIn('status', ['available', 'reserved'])->update(['status' => 'occupied']);

        $this->audit->record($user, 'create', 'Lease', $record->id, "Created lease {$record->code}");
        $this->notifications->push([
            'kind' => 'lease',
            'title' => 'New lease created',
            'body' => "Lease {$record->code} is now on file.",
            'forUserId' => $record->tenantId,
            'link' => '/portal/lease',
        ]);
    }

    /** A lease with invoices or payments is part of the financial record and cannot be deleted — terminate it instead. */
    protected function beforeDelete(User $user, Model $record): void
    {
        abort_if(
            Invoice::query()->where('leaseId', $record->id)->exists() || Payment::query()->where('leaseId', $record->id)->exists(),
            422,
            'This lease has invoices or payments on record, so it cannot be deleted. Terminate it instead.',
        );

        abort_if(DepositService::heldOf($record->depositLedger) > 0, 422, 'This lease is holding a security deposit, so it cannot be deleted. Settle the deposit first.');

        if (in_array($record->status, self::CURRENT, true) && ! $this->holdsUnit($record->unitId, $record->id)) {
            Unit::query()->where('id', $record->unitId)->where('status', 'occupied')->update(['status' => 'available']);
        }
    }

    public function renew(User $user, string $id, string $newEndDate): Lease
    {
        /** @var Lease $lease */
        $lease = $this->locate($user, $id);
        $this->authorize($user, 'update', $lease);

        $this->assertNoOverlap($lease->unitId, $lease->startDate, $newEndDate, $lease->id);

        $lease->update(['status' => 'renewed', 'endDate' => $newEndDate]);
        $this->audit->record($user, 'edit', 'Lease', $lease->id, "Renewed lease {$lease->code} to {$newEndDate}");
        $this->notifications->push([
            'kind' => 'lease',
            'title' => 'Lease renewed',
            'body' => "Lease {$lease->code} has been renewed through {$newEndDate}.",
            'forUserId' => $lease->tenantId,
            'link' => '/portal/lease',
        ]);

        return $lease->refresh();
    }

    public function terminate(User $user, string $id): Lease
    {
        return $this->endTenancy($user, $id)[0];
    }

    /**
     * End a tenancy. The closing meter readings, if any, are saved first and in the same step as the termination, so the final
     * utility bill is raised on them: either all of it happens or, if a reading is not acceptable, none of it does.
     *
     * @param  array<int,array{meterId:string,value:int|float|string}>  $closingReadings  the readings taken at move-out
     * @return array{0:Lease,1:?Invoice} the ended lease, and the final utility bill if one was raised
     */
    public function endTenancy(User $user, string $id, array $closingReadings = []): array
    {
        /** @var Lease $lease */
        $lease = $this->locate($user, $id);
        $this->authorize($user, 'update', $lease);

        abort_if($closingReadings !== [] && ! app(Modules::class)->enabled('utilities'), 422,
            'The '.Modules::DEFINITIONS['utilities']['label'].' module is turned off, so closing readings cannot be taken.');

        $readings = $this->acceptableClosingReadings($lease, $closingReadings);

        DB::transaction(function () use ($user, $lease, $readings) {
            foreach ($readings as [$meter, $value]) {
                app(MeterService::class)->addReading($user, $meter->id, $value, now()->toDateString());
            }

            $lease->update(['status' => 'terminated', 'terminatedOn' => now()->toDateString()]);
            // The unit only comes back on the market if nobody else is holding it (e.g. a lease due to start next).
            if (! $this->holdsUnit($lease->unitId, $lease->id)) {
                Unit::query()->where('id', $lease->unitId)->update(['status' => 'available']);
            }
        });

        $logged = count($readings);
        $this->audit->record($user, 'edit', 'Lease', $lease->id, "Terminated lease {$lease->code}".($logged ? " (closing readings logged for {$logged} meter".($logged === 1 ? '' : 's').')' : ''));
        $this->notifications->push([
            'kind' => 'lease',
            'title' => 'Lease terminated',
            'body' => "Lease {$lease->code} has been terminated.",
            'forUserId' => $lease->tenantId,
            'link' => '/portal/lease',
        ]);
        $this->notifications->push([
            'kind' => 'lease',
            'title' => 'Unit now available',
            'body' => "Lease {$lease->code} ended — its unit is back on the market.",
            'forRole' => 'agent',
            'link' => "/properties/{$lease->propertyId}",
        ]);

        // The tenant is leaving: bill them for the use still unbilled on the unit's meters (now including the closing readings),
        // so it is on their account before the deposit is settled. Never fails the termination; nothing is billed if someone
        // else now holds the unit.
        $invoice = app(UtilityBillingService::class)->billFinal($user, $lease->refresh());

        return [$lease->refresh(), $invoice];
    }

    /**
     * Check the closing readings before anything is changed: each must be for a meter on this lease's unit and not below the
     * meter's current reading. Readings that equal the current one change nothing and are dropped.
     *
     * @param  array<int,array{meterId:string,value:int|float|string}>  $closingReadings
     * @return array<int,array{0:Meter,1:float}>
     */
    private function acceptableClosingReadings(Lease $lease, array $closingReadings): array
    {
        $accepted = [];

        foreach ($closingReadings as $reading) {
            $meter = Meter::query()->find($reading['meterId']);
            abort_unless($meter && $meter->unitId === $lease->unitId, 422, 'That meter is not on this lease\'s unit.');

            $value = (float) $reading['value'];
            abort_if($meter->currentReading !== null && $value < $meter->currentReading, 422, "{$meter->meterNumber}: the closing reading ({$value}) is lower than the current reading ({$meter->currentReading}).");

            if ($meter->currentReading === null || abs($value - $meter->currentReading) >= 0.0005) {
                $accepted[] = [$meter, $value];
            }
        }

        return $accepted;
    }
}

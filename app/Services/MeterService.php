<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\Meter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MeterService extends CrudService
{
    protected string $model = Meter::class;

    protected array $searchable = ['meterNumber'];

    protected array $filterable = ['propertyId', 'unitId', 'type'];

    protected array $sortable = ['id', 'meterNumber', 'type', 'currentReading'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'mtr';

    protected array $createDefaults = ['history' => []];

    protected bool $tenantUnitScoped = true;

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $this->scopeByProperty($query, $user);
    }

    /**
     * A meter shows what the people living in the unit use, so a tenant sees the meters of the unit they hold today —
     * not of one they rented once, which someone else now lives in. Same test as billing uses for "who holds the unit".
     */
    protected function tenantUnitIds(array $tenantIds)
    {
        $today = now()->toDateString();

        return Lease::query()->whereIn('tenantId', $tenantIds ?: ['__none__'])->whereIn('status', LeaseService::CURRENT)
            ->where('startDate', '<=', $today)->where('endDate', '>=', $today)->pluck('unitId');
    }

    /**
     * A meter's readings and history move only through addReading(), which keeps the previous/current pair and
     * the history in step. A plain edit may restate them but not change them — otherwise the figures a tenant
     * is billed from could be rewritten without a trace.
     */
    protected function prepareForUpdate(User $user, Model $record, array $data): array
    {
        foreach (['previousReading', 'currentReading'] as $field) {
            if (array_key_exists($field, $data)) {
                $same = $data[$field] === null ? $record->{$field} === null : abs((float) $data[$field] - (float) $record->{$field}) < 0.0005;
                abort_unless($same, 422, 'Record a new reading through /meters/{id}/readings.');
                unset($data[$field]);
            }
        }

        if (array_key_exists('history', $data)) {
            abort_unless(json_encode(array_values((array) $data['history'])) === json_encode(array_values((array) $record->history)), 422, 'Meter history cannot be edited; record a new reading instead.');
            unset($data['history']);
        }

        return $data;
    }

    /**
     * Record a new reading; rolls current → previous and appends to history.
     *
     * The meter is re-read under a row lock before it is changed — the same lock billing takes — so a reading is never
     * written from a stale copy: not over a billing marker that moved a moment ago (which would bill the same use
     * twice), and not over another reading logged at the same time (which would lose a history entry).
     */
    public function addReading(User $user, string $id, float $value, string $date): Meter
    {
        /** @var Meter $found */
        $found = $this->locate($user, $id);
        $this->authorize($user, 'update', $found);

        return DB::transaction(function () use ($id, $value, $date) {
            $meter = Meter::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            $history = $meter->history ?? [];
            $history[] = ['date' => $date, 'value' => $value];

            $meter->update([
                // A meter never yet billed keeps its starting point, so use between bills is not lost as readings roll on.
                'billedReading' => $meter->billedReading ?? $meter->previousReading ?? $meter->currentReading,
                'previousReading' => $meter->currentReading,
                'currentReading' => $value,
                'history' => $history,
            ]);

            return $meter->refresh();
        });
    }

    /**
     * Every meter this person could bill by hand, optionally within some properties: on a unit, with a reading,
     * in a property they can see and may edit meters of.
     *
     * @param  array<int,string>|null  $propertyIds
     * @return Collection<int,Meter>
     */
    public function billableBy(User $user, ?array $propertyIds = null): Collection
    {
        $query = $this->scopeQuery(Meter::query(), $user)->whereNotNull('unitId')->whereNotNull('currentReading')->orderBy('id');

        if ($propertyIds !== null) {
            $query->whereIn('propertyId', $propertyIds);
        }

        // Whether someone may edit a meter depends on who they are and which property it is in, not on the meter itself,
        // so ask once per property rather than once per meter (each answer costs a few queries).
        $mayEditIn = [];

        return $query->get()->filter(function (Meter $m) use ($user, &$mayEditIn) {
            return $mayEditIn[$m->propertyId] ??= Gate::forUser($user)->allows('update', $m);
        })->values();
    }

    /** Billing a meter is a management action: whoever may edit the meter may bill it. */
    public function assertMayBill(User $user, Meter $meter): void
    {
        $this->locate($user, $meter->id);
        $this->authorize($user, 'update', $meter);
    }
}

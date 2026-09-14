<?php

namespace App\Services;

use App\Models\Meter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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

    /** Record a new reading; rolls current → previous and appends to history. */
    public function addReading(User $user, string $id, float $value, string $date): Meter
    {
        /** @var Meter $meter */
        $meter = $this->locate($user, $id);
        $this->authorize($user, 'update', $meter);

        $history = $meter->history ?? [];
        $history[] = ['date' => $date, 'value' => $value];

        $meter->update([
            'previousReading' => $meter->currentReading,
            'currentReading' => $value,
            'history' => $history,
        ]);

        return $meter->refresh();
    }
}

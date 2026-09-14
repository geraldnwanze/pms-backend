<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UnitService extends CrudService
{
    protected function prepareForCreate(User $user, array $data): array
    {
        $data = parent::prepareForCreate($user, $data);

        // Keep the denormalised propertyId in step with the chosen building.
        if (empty($data['propertyId']) && ! empty($data['buildingId'])) {
            $data['propertyId'] = Building::query()->whereKey($data['buildingId'])->value('propertyId');
        }

        abort_if(
            ! $user->isAdmin() && ! empty($data['propertyId']) && ! $this->scope->canSee($user, $data['propertyId']),
            403,
            'You can only add units to a property you manage.',
        );

        return $data;
    }

    protected string $model = Unit::class;

    protected array $searchable = ['code', 'type'];

    protected array $filterable = ['propertyId', 'buildingId', 'status', 'furnishing'];

    protected array $sortable = ['id', 'code', 'type', 'floor', 'rent', 'status'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'unit';

    protected array $createDefaults = ['meterIds' => []];

    protected bool $tenantUnitScoped = true;

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $this->scopeByProperty($query, $user);
    }
}

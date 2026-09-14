<?php

namespace App\Services;

use App\Models\Building;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class BuildingService extends CrudService
{
    protected function prepareForCreate(User $user, array $data): array
    {
        $data = parent::prepareForCreate($user, $data);

        abort_if(
            ! $user->isAdmin() && ! empty($data['propertyId']) && ! $this->scope->canSee($user, $data['propertyId']),
            403,
            'You can only add buildings to a property you manage.',
        );

        return $data;
    }

    protected string $model = Building::class;

    protected array $searchable = ['name', 'code', 'description'];

    protected array $filterable = ['propertyId'];

    protected array $sortable = ['id', 'name', 'code', 'floors'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'bld';

    protected ?string $codeColumn = 'code';

    protected string $codePrefix = 'BLD';

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $this->scopeByProperty($query, $user);
    }
}

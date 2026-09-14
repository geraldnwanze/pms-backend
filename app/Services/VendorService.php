<?php

namespace App\Services;

use App\Models\Property;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;

class VendorService extends CrudService
{
    protected string $model = Vendor::class;

    protected array $searchable = ['name', 'service', 'email', 'phone'];

    protected array $filterable = ['ownerId', 'service'];

    protected array $sortable = ['id', 'name', 'service', 'totalPaid', 'outstanding'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'vnd';

    protected array $createDefaults = ['totalPaid' => 0, 'outstanding' => 0];

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        $propertyIds = $this->scope->visibleIds($user);

        if ($propertyIds === null) {
            return $query;
        }

        // Vendors belonging to any owner of a property the user can see.
        $ownerIds = Property::query()
            ->whereIn('id', $propertyIds)
            ->pluck('owners')
            ->flatMap(fn ($owners) => collect($owners)->pluck('ownerId'))
            ->push($user->id)
            ->unique()
            ->values()
            ->all();

        return $query->whereIn('ownerId', $ownerIds ?: ['__none__']);
    }
}

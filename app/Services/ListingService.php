<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ListingService extends CrudService
{
    protected string $model = Listing::class;

    protected array $searchable = ['title', 'description'];

    protected array $filterable = ['propertyId', 'unitId', 'status', 'agentId'];

    protected array $sortable = ['id', 'title', 'price', 'status', 'publishedAt'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'lst';

    protected array $nowOnCreate = ['publishedAt'];

    protected array $createDefaults = ['status' => 'published'];

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $this->scopeByProperty($query, $user);
    }
}

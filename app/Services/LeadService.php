<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class LeadService extends CrudService
{
    protected string $model = Lead::class;

    protected array $searchable = ['name', 'email', 'phone', 'notes'];

    protected array $filterable = ['status', 'agentId', 'propertyId', 'listingId'];

    protected array $sortable = ['id', 'name', 'status', 'createdAt', 'viewingDate'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'lead';

    protected array $nowOnCreate = ['createdAt'];

    protected array $createDefaults = ['status' => 'new'];

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        if (in_array($user->role, ['admin', 'manager'], true)) {
            return $query;
        }

        if ($user->role === 'agent') {
            return $query->where('agentId', $user->id);
        }

        $ids = $this->scope->visibleIds($user);

        return $ids === null ? $query : $query->whereIn('propertyId', $ids);
    }
}

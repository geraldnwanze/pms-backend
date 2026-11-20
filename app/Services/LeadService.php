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
        if ($user->isAdmin()) {
            return $query;
        }

        $ids = $this->scope->visibleIds($user) ?? [];

        // An agent works their own leads — but only on properties they still belong to. A lead with no
        // property is for administrators and whichever agent it is assigned to.
        if ($user->role === 'agent') {
            return $query->where('agentId', $user->id)
                ->where(fn ($q) => $q->whereIn('propertyId', $ids ?: ['__none__'])->orWhereNull('propertyId'));
        }

        // Everyone else (managers included) sees the prospects of the properties they run — not other portfolios'.
        return $query->whereIn('propertyId', $ids ?: ['__none__']);
    }
}

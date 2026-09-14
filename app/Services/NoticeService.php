<?php

namespace App\Services;

use App\Models\Notice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class NoticeService extends CrudService
{
    protected string $model = Notice::class;

    protected array $searchable = ['title', 'body'];

    protected array $filterable = ['audience', 'propertyId', 'createdById'];

    protected array $sortable = ['id', 'title', 'audience', 'createdAt'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'notice';

    protected array $nowOnCreate = ['createdAt'];

    protected array $actorOnCreate = ['createdById'];

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        $ids = $this->scope->visibleIds($user);

        if ($ids !== null) {
            $query->where(fn ($q) => $q->whereIn('propertyId', $ids)->orWhereNull('propertyId'));
        }

        // Audience gate — a tenant only sees tenant/all notices, staff never sees owner-only.
        $audiences = match ($user->role) {
            'admin' => ['all', 'owners', 'tenants', 'staff'],
            'owner' => ['all', 'owners'],
            'tenant' => ['all', 'tenants'],
            default => ['all', 'staff'],
        };

        return $query->whereIn('audience', $audiences);
    }
}

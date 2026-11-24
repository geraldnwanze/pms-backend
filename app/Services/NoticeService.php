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

    protected function prepareForCreate(User $user, array $data): array
    {
        $data = parent::prepareForCreate($user, $data);

        // Who posted it, and when, is the server's to say.
        $data['createdById'] = $user->id;
        $data['createdAt'] = now()->toIso8601String();

        return $data;
    }

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        $ids = $this->scope->visibleIds($user);

        if ($ids !== null) {
            // A notice with no property was posted to "everyone I work with": it reaches the poster's colleagues
            // (people on the same properties) and, if an administrator posted it, everyone. Never other owners'.
            $reach = array_merge($this->scope->colleagueIds($user) ?? [], User::query()->whereIn('role', User::ADMIN_ROLES)->pluck('id')->all());
            $query->where(fn ($q) => $q->whereIn('propertyId', $ids ?: ['__none__'])
                ->orWhere(fn ($w) => $w->whereNull('propertyId')->whereIn('createdById', $reach)));
        }

        // Audience gate — a tenant only sees tenant/all notices, staff never sees owner-only.
        $audiences = match ($user->role) {
            'superadmin', 'admin' => ['all', 'owners', 'tenants', 'staff'],
            'owner' => ['all', 'owners'],
            'tenant' => ['all', 'tenants'],
            default => ['all', 'staff'],
        };

        return $query->whereIn('audience', $audiences);
    }
}

<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantService extends CrudService
{
    protected string $model = Tenant::class;

    protected array $searchable = ['name', 'email', 'phone', 'identityNumber'];

    protected array $filterable = ['ownerId'];

    protected array $sortable = ['id', 'name', 'email', 'createdAt'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'ten';

    protected array $nowOnCreate = ['createdAt'];

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        $ids = $this->scope->visibleTenantIds($user);

        return $ids === null ? $query : $query->whereIn('id', $ids);
    }

    protected function beforeDelete(User $user, Model $record): void
    {
        abort_if(Lease::query()->where('tenantId', $record->id)->exists(), 422, 'This tenant has leases on record, so they cannot be deleted.');
    }
}

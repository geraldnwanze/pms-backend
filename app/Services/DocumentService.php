<?php

namespace App\Services;

use App\Models\DocumentRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DocumentService extends CrudService
{
    protected string $model = DocumentRecord::class;

    protected array $searchable = ['name'];

    protected array $filterable = ['category', 'propertyId', 'unitId', 'tenantId', 'uploadedById'];

    protected array $sortable = ['id', 'name', 'category', 'sizeKb', 'uploadedAt'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'doc';

    protected array $nowOnCreate = ['uploadedAt'];

    protected array $actorOnCreate = ['uploadedById'];

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        // Tenant-only users see just the documents filed against their own record.
        if ($user->isTenantOnly()) {
            return $query->whereIn('tenantId', $this->scope->ownTenantIds($user) ?: ['__none__']);
        }

        $ids = $this->scope->visibleIds($user);

        return $ids === null
            ? $query
            : $query->where(fn ($q) => $q->whereIn('propertyId', $ids)->orWhereNull('propertyId'));
    }
}

<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AuditQueryService extends CrudService
{
    protected string $model = AuditLog::class;

    protected array $searchable = ['summary', 'entity', 'entityId'];

    protected array $filterable = ['action', 'entity', 'userId'];

    protected array $sortable = ['id', 'timestamp', 'action', 'entity'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'aud';

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $query;
    }
}

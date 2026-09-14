<?php

namespace App\Services;

use App\Models\Inspection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class InspectionService extends CrudService
{
    protected string $model = Inspection::class;

    protected array $searchable = ['code', 'notes'];

    protected array $filterable = ['propertyId', 'unitId', 'type', 'status', 'inspectorId'];

    protected array $sortable = ['id', 'code', 'scheduledDate', 'completedDate', 'type', 'status'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'insp';

    protected ?string $codeColumn = 'code';

    protected string $codePrefix = 'INS';

    protected array $createDefaults = ['status' => 'scheduled'];

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $this->scopeByProperty($query, $user);
    }
}

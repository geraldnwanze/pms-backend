<?php

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PropertyService extends CrudService
{
    protected string $model = Property::class;

    protected array $searchable = ['code', 'name', 'city', 'address', 'description'];

    protected array $filterable = ['type', 'status', 'city', 'state'];

    protected array $sortable = ['id', 'code', 'name', 'city', 'yearBuilt', 'createdAt'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'prop';

    protected ?string $codeColumn = 'code';

    protected string $codePrefix = 'PROP';

    protected array $nowOnCreate = ['createdAt'];

    protected array $createDefaults = ['status' => 'active'];

    public function __construct(PropertyScope $scope, private AuditService $audit)
    {
        parent::__construct($scope);
    }

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        $ids = $this->scope->visibleIds($user);

        return $ids === null ? $query : $query->whereIn('id', $ids);
    }

    protected function prepareForCreate(User $user, array $data): array
    {
        $data = parent::prepareForCreate($user, $data);

        // The creator is the property's owner.
        $data['owners'] = [['ownerId' => $user->id, 'percentage' => 100]];
        $data['managerId'] = null;
        $data['assignedStaffIds'] = [];
        $data['amenities'] ??= [];
        $data['photos'] ??= [];

        return $data;
    }

    protected function afterCreate(User $user, Model $record, array $data): void
    {
        PropertyMember::query()->create([
            'id' => 'mem_'.Str::lower(Str::random(12)),
            'propertyId' => $record->id,
            'userId' => $user->id,
            'email' => Str::lower($user->email),
            'role' => 'owner',
            'status' => 'active',
            'invitedById' => $user->id,
            'invitedAt' => now()->toIso8601String(),
            'respondedAt' => now()->toIso8601String(),
        ]);

        $user->recomputeRole();

        $this->audit->record($user, 'create', 'Property', $record->id, "Registered property {$record->name}");
    }
}

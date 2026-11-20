<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyMember;
use App\Models\Unit;
use App\Models\User;
use App\Support\ImageStore;
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

    /** Public-disk folder holding uploaded property photos. */
    private const PHOTO_DIR = 'properties';

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
        $data['photos'] = ImageStore::persistAll($data['photos'] ?? [], self::PHOTO_DIR);

        return $data;
    }

    protected function prepareForUpdate(User $user, Model $record, array $data): array
    {
        // Ownership and the team are managed through memberships/invitations, not by
        // writing these columns directly. `owners` in particular drives owner
        // statements (MetricsService), so an editable value would let a property
        // editor grant anyone a view of its finances.
        abort_if(
            ! $user->isAdmin() && array_intersect_key($data, array_flip(['owners', 'managerId', 'assignedStaffIds'])) !== [],
            403,
            'Ownership and team assignments are changed by inviting or removing members.',
        );

        if (array_key_exists('photos', $data)) {
            $data['photos'] = ImageStore::persistAll($data['photos'] ?? [], self::PHOTO_DIR);
            ImageStore::deleteRemoved($record->photos ?? [], $data['photos'], self::PHOTO_DIR);
        }

        return $data;
    }

    protected function beforeDelete(User $user, Model $record): void
    {
        abort_if(
            Building::query()->where('propertyId', $record->id)->exists() || Unit::query()->where('propertyId', $record->id)->exists() || Lease::query()->where('propertyId', $record->id)->exists(),
            422,
            'This property still has buildings, units or leases. Archive it instead of deleting it.',
        );

        ImageStore::deleteRemoved($record->photos ?? [], [], self::PHOTO_DIR);
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

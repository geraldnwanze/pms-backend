<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Lease;
use App\Models\Unit;
use App\Models\User;
use App\Support\ImageStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UnitService extends CrudService
{
    protected function prepareForCreate(User $user, array $data): array
    {
        $data = parent::prepareForCreate($user, $data);

        // Keep the denormalised propertyId in step with the chosen building.
        if (empty($data['propertyId']) && ! empty($data['buildingId'])) {
            $data['propertyId'] = Building::query()->whereKey($data['buildingId'])->value('propertyId');
        }

        abort_if(
            ! $user->isAdmin() && ! empty($data['propertyId']) && ! $this->scope->canSee($user, $data['propertyId']),
            403,
            'You can only add units to a property you manage.',
        );

        $this->assertBuildingInProperty($data['buildingId'] ?? null, $data['propertyId'] ?? null);

        $data['photos'] = ImageStore::persistAll($data['photos'] ?? [], self::PHOTO_DIR);

        return $data;
    }

    protected function prepareForUpdate(User $user, Model $record, array $data): array
    {
        $this->forbidReparenting($record, $data);
        $this->assertBuildingInProperty($data['buildingId'] ?? null, $record->propertyId);

        // A unit someone is renting is not "available": end the lease first (that is what frees the unit).
        abort_if(
            ($data['status'] ?? null) === 'available' && $record->status !== 'available'
                && Lease::query()->where('unitId', $record->id)->whereIn('status', LeaseService::CURRENT)->exists(),
            422,
            'This unit has a current lease. Terminate the lease to make the unit available.',
        );

        if (array_key_exists('photos', $data)) {
            $data['photos'] = ImageStore::persistAll($data['photos'] ?? [], self::PHOTO_DIR);
            ImageStore::deleteRemoved($record->photos ?? [], $data['photos'], self::PHOTO_DIR);
        }

        return $data;
    }

    protected function beforeDelete(User $user, Model $record): void
    {
        abort_if(Lease::query()->where('unitId', $record->id)->exists(), 422, 'This unit has leases on record, so it cannot be deleted. Mark it unavailable instead.');

        ImageStore::deleteRemoved($record->photos ?? [], [], self::PHOTO_DIR);
    }

    /** A unit's building must sit inside the unit's own property (no attaching units to someone else's block). */
    private function assertBuildingInProperty(?string $buildingId, ?string $propertyId): void
    {
        if ($buildingId === null || $propertyId === null) {
            return;
        }

        abort_unless(
            Building::query()->whereKey($buildingId)->where('propertyId', $propertyId)->exists(),
            422,
            'That building does not belong to this property.',
        );
    }

    /** Public-disk folder holding uploaded unit photos. */
    private const PHOTO_DIR = 'units';

    protected string $model = Unit::class;

    protected array $searchable = ['code', 'type'];

    protected array $filterable = ['propertyId', 'buildingId', 'status', 'furnishing'];

    protected array $sortable = ['id', 'code', 'type', 'floor', 'rent', 'status'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'unit';

    protected array $createDefaults = ['meterIds' => []];

    protected bool $tenantUnitScoped = true;

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $this->scopeByProperty($query, $user);
    }
}

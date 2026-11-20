<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Unit;
use App\Models\User;
use App\Support\ImageStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BuildingService extends CrudService
{
    protected function prepareForCreate(User $user, array $data): array
    {
        $data = parent::prepareForCreate($user, $data);

        abort_if(
            ! $user->isAdmin() && ! empty($data['propertyId']) && ! $this->scope->canSee($user, $data['propertyId']),
            403,
            'You can only add buildings to a property you manage.',
        );

        $data['photos'] = ImageStore::persistAll($data['photos'] ?? [], self::PHOTO_DIR);

        return $data;
    }

    protected function prepareForUpdate(User $user, Model $record, array $data): array
    {
        $this->forbidReparenting($record, $data);

        if (array_key_exists('photos', $data)) {
            $data['photos'] = ImageStore::persistAll($data['photos'] ?? [], self::PHOTO_DIR);
            ImageStore::deleteRemoved($record->photos ?? [], $data['photos'], self::PHOTO_DIR);
        }

        return $data;
    }

    protected function beforeDelete(User $user, Model $record): void
    {
        abort_if(Unit::query()->where('buildingId', $record->id)->exists(), 422, 'This building still has units. Remove or move them first.');

        ImageStore::deleteRemoved($record->photos ?? [], [], self::PHOTO_DIR);
    }

    /** Public-disk folder holding uploaded building (interior) photos. */
    private const PHOTO_DIR = 'buildings';

    protected string $model = Building::class;

    protected array $searchable = ['name', 'code', 'description'];

    protected array $filterable = ['propertyId'];

    protected array $sortable = ['id', 'name', 'code', 'floors'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'bld';

    protected ?string $codeColumn = 'code';

    protected string $codePrefix = 'BLD';

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $this->scopeByProperty($query, $user);
    }
}

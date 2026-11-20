<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VendorService extends CrudService
{
    protected string $model = Vendor::class;

    protected array $searchable = ['name', 'service', 'email', 'phone'];

    protected array $filterable = ['ownerId', 'service'];

    protected array $sortable = ['id', 'name', 'service', 'totalPaid', 'outstanding'];

    protected string $defaultSort = 'id';

    protected string $idPrefix = 'vnd';

    protected array $createDefaults = ['totalPaid' => 0, 'outstanding' => 0];

    protected function scopeQuery(Builder $query, User $user): Builder
    {
        $ownerIds = $this->scope->visibleOwnerIds($user);

        // Vendors belonging to any owner of a property the user can see.
        return $ownerIds === null ? $query : $query->whereIn('ownerId', $ownerIds ?: ['__none__']);
    }

    protected function prepareForCreate(User $user, array $data): array
    {
        $this->assertOwnerAllowed($user, $data['ownerId'] ?? null);

        return parent::prepareForCreate($user, $data);
    }

    protected function prepareForUpdate(User $user, Model $record, array $data): array
    {
        if (array_key_exists('ownerId', $data) && $data['ownerId'] !== $record->ownerId) {
            abort(422, 'A vendor cannot be moved to a different owner.');
        }

        return $data;
    }

    private function assertOwnerAllowed(User $user, ?string $ownerId): void
    {
        $allowed = $this->scope->visibleOwnerIds($user);

        abort_unless($allowed === null || in_array($ownerId, $allowed, true), 403, 'You can only add vendors to a portfolio you belong to.');
    }
}

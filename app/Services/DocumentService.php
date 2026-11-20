<?php

namespace App\Services;

use App\Models\DocumentRecord;
use App\Models\Unit;
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

        if ($ids === null) {
            return $query;
        }

        // A "portfolio-wide" document (no property) belongs to whoever uploaded it and the people they
        // work with — not to staff of every other owner's properties.
        $colleagues = $this->scope->colleagueIds($user) ?? [];

        return $query->where(fn ($q) => $q->whereIn('propertyId', $ids)
            ->orWhere(fn ($w) => $w->whereNull('propertyId')->whereIn('uploadedById', $colleagues)));
    }

    protected function prepareForCreate(User $user, array $data): array
    {
        $data = parent::prepareForCreate($user, $data);

        // Attribution and time are the server's to state, not the client's.
        $data['uploadedById'] = $user->id;
        $data['uploadedAt'] = now()->toIso8601String();

        // A document about a unit or a tenant lives under a property, so it can't leak outside it.
        if (empty($data['propertyId']) && ! empty($data['unitId'])) {
            $data['propertyId'] = Unit::query()->whereKey($data['unitId'])->value('propertyId');
        }
        abort_if(
            empty($data['propertyId']) && ! empty($data['tenantId']),
            422,
            'A document about a tenant must be filed under a property.',
        );

        return $data;
    }

    /** Whoever the UI lets manage documents (admins, managers) may delete them — within what they can see. */
    public function delete(User $user, string $id): void
    {
        $record = $this->locate($user, $id);

        abort_unless($user->hasPermission('manage_documents'), 403, 'This action is unauthorized.');

        $this->beforeDelete($user, $record);
        $record->delete();
    }
}

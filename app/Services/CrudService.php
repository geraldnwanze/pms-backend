<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\PmsModel;
use App\Models\User;
use App\Support\EntityRegistry;
use App\Support\Rbac;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Base application service: controller → service → model.
 *
 * Concrete services set {@see $model}, declare searchable/filterable/sortable
 * columns, and override {@see scopeQuery()} to apply RBAC visibility. Workflow
 * services add domain methods on top (approve a payment, renew a lease, …).
 *
 * No repository layer — services talk to Eloquent directly; the query scope and
 * lifecycle hooks are the seam for business rules.
 */
abstract class CrudService
{
    /** @var class-string<PmsModel> */
    protected string $model;

    /** Columns matched (OR, LIKE) by ?search= */
    protected array $searchable = [];

    /** Columns filterable via ?<col>= or ?filter[<col>]= (exact match) */
    protected array $filterable = [];

    /** Columns permitted in ?sort= (prefix with - for desc) */
    protected array $sortable = ['id'];

    protected string $defaultSort = 'id';

    protected int $perPage = 25;

    protected int $maxPerPage = 100;

    /** Prefix for generated ids (e.g. "prop" → "prop_ab12cd34ef56"). */
    protected string $idPrefix = 'rec';

    /** Column => value applied on create when the client omits the field. */
    protected array $createDefaults = [];

    /** Columns set to the current ISO timestamp on create when omitted. */
    protected array $nowOnCreate = [];

    /** Columns set to the acting user's id on create when omitted. */
    protected array $actorOnCreate = [];

    /** When set, a human code is generated on create: "<prefix>-<random>". */
    protected ?string $codeColumn = null;

    protected string $codePrefix = '';

    public function __construct(protected PropertyScope $scope) {}

    /** @return class-string<PmsModel> */
    public function modelClass(): string
    {
        return $this->model;
    }

    // ---- Read ----------------------------------------------------------------

    public function paginate(User $user, array $params): LengthAwarePaginator
    {
        $this->authorizeCollection($user, 'view');

        $query = $this->scopeQuery($this->model::query(), $user);

        $this->applySearch($query, $params['search'] ?? null);
        $this->applyFilters($query, $params);
        $this->applySort($query, $params['sort'] ?? null);

        $perPage = (int) ($params['per_page'] ?? $this->perPage);
        $perPage = max(1, min($perPage, $this->maxPerPage));

        return $query->paginate($perPage)->appends($params);
    }

    public function find(User $user, string $id): Model
    {
        $record = $this->locate($user, $id);

        $this->authorize($user, 'view', $record);

        return $record;
    }

    /**
     * Every record the user may see, unpaginated. Used by the v1 bootstrap
     * endpoint to hydrate the whole client store in one request.
     *
     * @return Collection<int, Model>
     */
    public function listAll(User $user): Collection
    {
        $this->authorizeCollection($user, 'view');

        return $this->scopeQuery($this->model::query(), $user)->get();
    }

    /**
     * Fetch a record the user is allowed to see. A record that exists but is
     * out of the user's scope yields 403 (not 404), so scope stays the single
     * source of visibility truth for both list and show.
     */
    protected function locate(User $user, string $id): Model
    {
        $record = $this->scopeQuery($this->model::query(), $user)->find($id);

        if (! $record) {
            $this->model::query()->findOrFail($id); // 404 if it truly doesn't exist
            abort(403, 'You do not have access to this record.');
        }

        return $record;
    }

    // ---- Write -------------------------------------------------------------

    public function create(User $user, array $data): Model
    {
        $this->authorizeCollection($user, 'manage');

        $data = $this->prepareForCreate($user, $data);
        $data['id'] ??= $this->newId();

        if ($this->model::query()->whereKey($data['id'])->exists()) {
            abort(409, "A record with id {$data['id']} already exists.");
        }

        $record = $this->model::query()->create($data);

        $this->afterCreate($user, $record, $data);

        return $record->refresh();
    }

    public function update(User $user, string $id, array $data): Model
    {
        $record = $this->locate($user, $id);

        $this->authorize($user, 'update', $record);

        $data = $this->prepareForUpdate($user, $record, $data);
        $record->fill($data)->save();

        $this->afterUpdate($user, $record, $data);

        return $record->refresh();
    }

    public function delete(User $user, string $id): void
    {
        $record = $this->locate($user, $id);

        $this->authorize($user, 'delete', $record);

        $this->beforeDelete($user, $record);

        $record->delete();
    }

    // ---- Extension points -------------------------------------------------

    /** Apply RBAC row visibility. Default: no restriction. */
    protected function scopeQuery(Builder $query, User $user): Builder
    {
        return $query;
    }

    /**
     * Standard property-based scoping used by most resources.
     *
     * Tenants are narrowed further than their property: to rows carrying their
     * own tenantId ({@see $tenantColumn}), or — for models without one — to
     * rows whose unit is on one of their leases ({@see $tenantUnitScoped}).
     */
    protected string $tenantColumn = 'tenantId';

    protected bool $tenantUnitScoped = false;

    protected function scopeByProperty(Builder $query, User $user, string $column = 'propertyId'): Builder
    {
        if ($user->isTenantOnly()) {
            $tenantIds = $this->scope->ownTenantIds($user);

            if ($this->tenantColumn && in_array($this->tenantColumn, $this->model::columns(), true)) {
                return $query->whereIn($this->tenantColumn, $tenantIds ?: ['__none__']);
            }

            if ($this->tenantUnitScoped) {
                $unitIds = Lease::query()->whereIn('tenantId', $tenantIds ?: ['__none__'])->pluck('unitId');
                $col = in_array('unitId', $this->model::columns(), true) ? 'unitId' : 'id';

                return $query->whereIn($col, $unitIds);
            }
        }

        $ids = $this->scope->visibleIds($user);

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }

    protected function prepareForCreate(User $user, array $data): array
    {
        foreach ($this->createDefaults as $column => $value) {
            $data[$column] ??= $value;
        }

        foreach ($this->nowOnCreate as $column) {
            $data[$column] ??= now()->toIso8601String();
        }

        foreach ($this->actorOnCreate as $column) {
            $data[$column] ??= $user->id;
        }

        if ($this->codeColumn && empty($data[$this->codeColumn])) {
            $data[$this->codeColumn] = $this->codePrefix.'-'.Str::upper(Str::random(5));
        }

        return $data;
    }

    protected function prepareForUpdate(User $user, Model $record, array $data): array
    {
        return $data;
    }

    protected function afterCreate(User $user, Model $record, array $data): void {}

    protected function afterUpdate(User $user, Model $record, array $data): void {}

    protected function beforeDelete(User $user, Model $record): void {}

    // ---- Internals -------------------------------------------------------

    protected function authorize(User $user, string $ability, ?Model $model = null): void
    {
        Gate::forUser($user)->authorize($ability, $model ?? $this->model);
    }

    /**
     * Collection-level gate (list / create) — Laravel's class-based policy
     * methods only receive the user, so the resource-role + capability check
     * for `viewAny`/`create` lives here instead of the policy.
     *
     * @param  'view'|'manage'  $mode
     */
    protected function authorizeCollection(User $user, string $mode): void
    {
        abort_unless($this->mayAccessCollection($user, $mode), 403, 'This action is unauthorized.');
    }

    /** @param  'view'|'manage'  $mode */
    public function mayAccessCollection(User $user, string $mode): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $key = EntityRegistry::keyForModel($this->model);
        $roles = config("rbac.resources.{$key}.{$mode}", []);
        $capability = $mode === 'view' ? 'view' : 'create';

        if (in_array($user->role, $roles, true) && Rbac::roleHasPermission($user->role, $capability)) {
            return true;
        }

        // Owners may create structural records (properties, buildings, units)
        // without the blanket `create` capability. The target property is
        // scope-checked in the structural service's prepareForCreate().
        return $mode === 'manage' && Rbac::ownerManagesStructure($user->role, $key);
    }

    protected function newId(?string $prefix = null): string
    {
        return ($prefix ?? $this->idPrefix).'_'.Str::lower(Str::random(12));
    }

    private function applySearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '' || empty($this->searchable)) {
            return;
        }

        $query->where(function (Builder $q) use ($term) {
            foreach ($this->searchable as $column) {
                $q->orWhere($column, 'ilike', "%{$term}%");
            }
        });
    }

    private function applyFilters(Builder $query, array $params): void
    {
        $filters = $params['filter'] ?? [];
        foreach ($this->filterable as $column) {
            $value = $filters[$column] ?? $params[$column] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            is_array($value)
                ? $query->whereIn($column, $value)
                : $query->where($column, $value);
        }
    }

    private function applySort(Builder $query, ?string $sort): void
    {
        $sort = $sort ?: $this->defaultSort;
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, $this->sortable, true)) {
            $column = $this->defaultSort;
            $direction = 'asc';
        }

        $query->orderBy($column, $direction);
    }
}

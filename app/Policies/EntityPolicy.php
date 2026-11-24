<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Services\PropertyScope;
use App\Support\EntityRegistry;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Model;

/**
 * Instance-level policy for every v1 resource. Three checks compose:
 *
 *  1. Resource access — is the role allowed to read/write this resource at all?
 *     (config/rbac.php `resources`, mirrors the frontend nav.)
 *  2. Capability — does the role hold the verb permission? (rbac `permissions`.)
 *  3. Visibility — is the specific record in the user's property/tenant scope?
 *     (App\Services\PropertyScope.)
 *
 * The collection-level gates (list / create) live in
 * CrudService::authorizeCollection() because Laravel's class-based policy
 * methods don't receive the model class.
 */
class EntityPolicy
{
    public function __construct(private PropertyScope $scope) {}

    /** Admins bypass every check. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, Model $model): bool
    {
        return $this->roleMay($user, $model::class, 'view')
            && $user->hasPermission('view')
            && $this->visible($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        // An owner may edit the structure (property details, buildings, units)
        // of a property they own, despite the `owner` role lacking `edit`.
        if (Rbac::ownerManagesStructure($user->role, EntityRegistry::keyForModel($model::class))
            && $this->ownsProperty($user, $model)) {
            return true;
        }

        return $this->roleMay($user, $model::class, 'manage')
            && $user->hasPermission('edit')
            && $this->visible($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->roleMay($user, $model::class, 'manage')
            && $user->hasPermission('delete')
            && $this->visible($user, $model);
    }

    // ---- Workflow abilities --------------------------------------------------

    public function approve(User $user, Model $model): bool
    {
        if ($model instanceof Payment) {
            return $user->canApprovePayments() && $this->visible($user, $model);
        }

        return $this->roleMay($user, $model::class, 'manage')
            && $user->hasPermission('approve')
            && $this->visible($user, $model);
    }

    public function reject(User $user, Model $model): bool
    {
        return $this->approve($user, $model);
    }

    public function refund(User $user, Model $model): bool
    {
        return $user->canApprovePayments() && $this->visible($user, $model);
    }

    // ---- Internals -------------------------------------------------------

    private function roleMay(User $user, string $modelClass, string $mode): bool
    {
        $key = EntityRegistry::keyForModel($modelClass);

        if ($key === null) {
            return false;
        }

        return in_array($user->role, config("rbac.resources.{$key}.{$mode}", []), true);
    }

    /** True when the record belongs to a property the user is an active owner of. */
    private function ownsProperty(User $user, Model $model): bool
    {
        $propertyId = $model instanceof Property
            ? $model->getKey()
            : ($model->getAttributes()['propertyId'] ?? null);

        return $propertyId !== null && $user->roleOnProperty($propertyId) === 'owner';
    }

    private function visible(User $user, Model $model): bool
    {
        if ($model instanceof AuditLog) {
            return $user->isAdmin() || $user->role === 'finance';
        }

        $attributes = $model->getAttributes();

        if ($user->isTenantOnly() && array_key_exists('tenantId', $attributes)) {
            return in_array($model->getAttribute('tenantId'), $user->tenantRecordIds(), true);
        }

        if (array_key_exists('propertyId', $attributes)) {
            return $this->scope->canSee($user, $model->getAttribute('propertyId'));
        }

        return true;
    }
}

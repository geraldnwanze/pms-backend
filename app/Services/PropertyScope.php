<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves which properties a user may see.
 *
 * Access is granted entirely through `property_members`: a user sees exactly
 * the properties they hold an **active** membership on. A registered user with
 * no accepted invitations sees nothing. Admins see everything.
 *
 * `visibleIds()` returns null for "no restriction" (admin) or an array of ids.
 */
class PropertyScope
{
    /** Per-request cache of active membership property ids, keyed by user id. */
    private array $cache = [];

    /** @return array<int,string>|null */
    public function visibleIds(User $user): ?array
    {
        if ($user->role === 'admin') {
            return null;
        }

        return $this->cache[$user->id] ??= PropertyMember::query()
            ->where('userId', $user->id)
            ->where('status', 'active')
            ->pluck('propertyId')
            ->unique()
            ->values()
            ->all();
    }

    public function canSee(User $user, ?string $propertyId): bool
    {
        if ($propertyId === null) {
            return true; // portfolio-wide record (e.g. a global notice)
        }

        $ids = $this->visibleIds($user);

        return $ids === null || in_array($propertyId, $ids, true);
    }

    /**
     * Tenant ids visible to a user. Admin: all. A tenant-only user: just the
     * tenant records their own memberships point at. Everyone else: tenants on
     * a lease on one of their visible properties.
     *
     * @return array<int,string>|null
     */
    public function visibleTenantIds(User $user): ?array
    {
        if ($user->role === 'admin') {
            return null;
        }

        if ($user->isTenantOnly()) {
            return PropertyMember::query()
                ->where('userId', $user->id)
                ->where('role', 'tenant')
                ->where('status', 'active')
                ->whereNotNull('tenantId')
                ->pluck('tenantId')
                ->unique()
                ->values()
                ->all();
        }

        $propertyIds = $this->visibleIds($user);

        return Lease::query()
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('propertyId', $propertyIds))
            ->pluck('tenantId')
            ->unique()
            ->values()
            ->all();
    }

    /** Tenant records a tenant-only user owns (for unit/lease narrowing). */
    public function ownTenantIds(User $user): array
    {
        return PropertyMember::query()
            ->where('userId', $user->id)
            ->where('role', 'tenant')
            ->where('status', 'active')
            ->whereNotNull('tenantId')
            ->pluck('tenantId')
            ->all();
    }

    /** @return Collection<int,string> */
    public function visiblePropertyCollection(User $user): Collection
    {
        $ids = $this->visibleIds($user);

        return $ids === null
            ? Property::query()->pluck('id')
            : collect($ids);
    }
}

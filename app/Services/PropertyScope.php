<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyMember;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
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

        $onLeases = Lease::query()
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('propertyId', $propertyIds))
            ->pluck('tenantId');

        // A tenant registered under this portfolio is theirs to see before any lease exists (and after one ends) —
        // otherwise the person who just registered them could not find them again.
        $filedHere = Tenant::query()->whereIn('ownerId', $this->visibleOwnerIds($user) ?: ['__none__'])->pluck('id');

        return $onLeases->merge($filedHere)->unique()->values()->all();
    }

    /**
     * Owner portfolios a user may file records under: the owners of the properties they can see, plus
     * themselves (a manager registering a tenant or vendor with no owner picks their own id). Null = admin.
     *
     * @return array<int,string>|null
     */
    public function visibleOwnerIds(User $user): ?array
    {
        $propertyIds = $this->visibleIds($user);

        if ($propertyIds === null) {
            return null;
        }

        return Property::query()->whereIn('id', $propertyIds)->pluck('owners')
            ->flatMap(fn ($owners) => collect($owners)->pluck('ownerId'))
            ->push($user->id)
            ->when($user->ownerId, fn ($c) => $c->push($user->ownerId))
            ->filter()->unique()->values()->all();
    }

    /**
     * The people this user works with: active members of the properties they can see, plus themselves.
     * "Portfolio-wide" records (no property) are shared with exactly these people. Null = admin (everyone).
     *
     * @return array<int,string>|null
     */
    public function colleagueIds(User $user): ?array
    {
        $propertyIds = $this->visibleIds($user);

        if ($propertyIds === null) {
            return null;
        }

        return PropertyMember::query()->whereIn('propertyId', $propertyIds ?: ['__none__'])->where('status', 'active')
            ->whereNotNull('userId')->pluck('userId')->push($user->id)->unique()->values()->all();
    }

    /** Can this user see (or file records under) this tenant? */
    public function canReachTenant(User $user, string $tenantId): bool
    {
        $visible = $this->visibleTenantIds($user);

        if ($visible === null || in_array($tenantId, $visible, true)) {
            return true;
        }

        $owner = Tenant::query()->whereKey($tenantId)->value('ownerId');
        $owners = $this->visibleOwnerIds($user);

        return $owner !== null && ($owners === null || in_array($owner, $owners, true));
    }

    /**
     * May this actor attach this person (as assignee, inspector, agent) to a record? They must be
     * an administrator or an active member of the record's property — or, when the record has no
     * property, of any property the actor can see (which includes the actor themselves).
     */
    public function canReachUser(User $actor, string $userId, ?string $propertyId): bool
    {
        if (User::query()->whereKey($userId)->value('role') === 'admin') {
            return true;
        }

        $members = PropertyMember::query()->where('userId', $userId)->where('status', 'active');

        if ($propertyId !== null && $propertyId !== '') {
            return $members->where('propertyId', $propertyId)->exists();
        }

        $visible = $this->visibleIds($actor);

        return $members->when($visible !== null, fn ($q) => $q->whereIn('propertyId', $visible ?: ['__none__']))->exists();
    }

    /** May this actor use this vendor? Vendors belong to an owner's portfolio. */
    public function canReachVendor(User $actor, string $vendorId): bool
    {
        $owners = $this->visibleOwnerIds($actor);
        $owner = Vendor::query()->whereKey($vendorId)->value('ownerId');

        return $owner !== null && ($owners === null || in_array($owner, $owners, true));
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

<?php

namespace App\Support;

use App\Models\Property;
use App\Models\PropertyMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves the User accounts that should receive a transactional email for a
 * given event. Only users with a login are returned — tenant records without an
 * account, or invited-but-not-accepted members, are skipped.
 */
class Recipients
{
    /** Accounts that represent a tenant record (portal logins). */
    public static function tenantUsers(?string $tenantId): Collection
    {
        if (! $tenantId) {
            return collect();
        }

        $ids = PropertyMember::query()
            ->where('role', 'tenant')->where('status', 'active')
            ->where('tenantId', $tenantId)
            ->pluck('userId')
            ->filter()
            ->all();

        return User::query()
            ->where(fn ($q) => $q->where('tenantId', $tenantId)->orWhereIn('id', $ids ?: ['__none__']))
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * The same answer as tenantUsers() for many tenants at once, in two queries however many there are (a bulk run emails
     * one tenant per unit).
     *
     * @param  array<int,string>  $tenantIds
     * @return Collection<string,Collection<int,User>> keyed by tenant id; a tenant with no login maps to an empty collection
     */
    public static function tenantUsersByTenant(array $tenantIds): Collection
    {
        $tenantIds = array_values(array_unique(array_filter($tenantIds)));
        if (! $tenantIds) {
            return collect();
        }

        $memberOf = PropertyMember::query()
            ->where('role', 'tenant')->where('status', 'active')->whereIn('tenantId', $tenantIds)
            ->get(['userId', 'tenantId'])->filter(fn ($m) => $m->userId)->groupBy('tenantId')->map(fn ($rows) => $rows->pluck('userId')->all());

        $users = User::query()
            ->where(fn ($q) => $q->whereIn('tenantId', $tenantIds)->orWhereIn('id', $memberOf->flatten()->unique()->all() ?: ['__none__']))
            ->get();

        return collect($tenantIds)->mapWithKeys(fn (string $tenantId) => [
            $tenantId => $users->filter(fn (User $u) => $u->tenantId === $tenantId || in_array($u->id, $memberOf[$tenantId] ?? [], true))->unique('id')->values(),
        ]);
    }

    /** Owners + managers of a property (plus its denormalised managerId). */
    public static function propertyManagers(string $propertyId): Collection
    {
        $memberIds = PropertyMember::query()
            ->where('propertyId', $propertyId)
            ->whereIn('role', ['owner', 'manager'])
            ->where('status', 'active')
            ->pluck('userId')
            ->filter()
            ->all();

        $managerId = Property::query()->whereKey($propertyId)->value('managerId');
        if ($managerId) {
            $memberIds[] = $managerId;
        }

        return User::query()->whereIn('id', array_values(array_unique($memberIds)) ?: ['__none__'])
            ->get()->values();
    }

    /**
     * Finance staff who can act on a payment: every admin, plus finance-role
     * users — narrowed to those with a membership on the payment's property
     * when one is given.
     */
    public static function paymentApprovers(?string $propertyId = null): Collection
    {
        $admins = User::query()->where('role', 'admin')->get();

        $financeQuery = User::query()->where('role', 'finance');
        if ($propertyId) {
            $financeIds = PropertyMember::query()
                ->where('propertyId', $propertyId)->where('role', 'finance')->where('status', 'active')
                ->pluck('userId')->filter()->all();
            $financeQuery->whereIn('id', $financeIds ?: ['__none__']);
        }

        return $admins->merge($financeQuery->get())->unique('id')->values();
    }
}

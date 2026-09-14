<?php

namespace App\Support;

class Rbac
{
    /**
     * Structural resources a property owner may create/edit on properties they
     * own, even though the `owner` role deliberately lacks the blanket
     * `create`/`edit` capability (owners are otherwise oversight-only). Row-level
     * property scope is still enforced — see EntityPolicy::ownsProperty() and
     * the structural services' prepareForCreate().
     */
    public const OWNER_STRUCTURAL = ['properties', 'buildings', 'units'];

    public static function ownerManagesStructure(?string $role, ?string $resourceKey): bool
    {
        return $role === 'owner' && in_array($resourceKey, self::OWNER_STRUCTURAL, true);
    }

    public static function roleHasPermission(?string $role, string $permission): bool
    {
        if (! $role) {
            return false;
        }

        $granted = config("rbac.permissions.{$role}", []);

        return in_array($permission, $granted, true);
    }

    public static function isPaymentApprover(?string $role): bool
    {
        return in_array($role, config('rbac.payment_approvers', []), true);
    }
}

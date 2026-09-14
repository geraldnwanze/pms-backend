<?php

namespace App\Models;

use App\Support\Rbac;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
        'created_at',
        'updated_at',
    ];

    /**
     * Role precedence — the first one a user holds (via active membership or the
     * admin flag) becomes their denormalised `role` for capability checks and
     * UI labelling. `user` is the base: registered, but not yet on any property.
     */
    public const ROLE_PRECEDENCE = ['admin', 'owner', 'finance', 'manager', 'agent', 'maintenance', 'tenant', 'user'];

    protected function casts(): array
    {
        return [
            'assignedPropertyIds' => 'array',
            'twoFactorEnabled' => 'boolean',
            'password' => 'hashed',
        ];
    }

    // ---- Memberships ---------------------------------------------------------

    public function memberships()
    {
        return $this->hasMany(PropertyMember::class, 'userId');
    }

    /** @return array<int,string> distinct roles from the user's active memberships */
    public function membershipRoles(): array
    {
        return PropertyMember::query()
            ->where('userId', $this->id)
            ->where('status', 'active')
            ->pluck('role')
            ->unique()
            ->values()
            ->all();
    }

    public function roleOnProperty(string $propertyId): ?string
    {
        return PropertyMember::query()
            ->where('userId', $this->id)
            ->where('propertyId', $propertyId)
            ->where('status', 'active')
            ->value('role');
    }

    /** True when the only thing this user is anywhere is a tenant. */
    public function isTenantOnly(): bool
    {
        $roles = $this->membershipRoles();

        return $roles !== [] && $roles === ['tenant'];
    }

    /** Tenant record ids this user represents (active tenant memberships). */
    public function tenantRecordIds(): array
    {
        $ids = PropertyMember::query()
            ->where('userId', $this->id)
            ->where('role', 'tenant')
            ->where('status', 'active')
            ->whereNotNull('tenantId')
            ->pluck('tenantId')
            ->all();

        if ($this->tenantId) {
            $ids[] = $this->tenantId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Recompute and persist `role` from the admin flag + active memberships.
     * Called whenever a membership is accepted, declined or revoked.
     */
    public function recomputeRole(): string
    {
        if ($this->role === 'admin') {
            return 'admin';
        }

        $held = $this->membershipRoles();

        $resolved = 'user';
        foreach (self::ROLE_PRECEDENCE as $role) {
            if ($role === 'admin') {
                continue;
            }
            if (in_array($role, $held, true)) {
                $resolved = $role;
                break;
            }
        }

        if ($this->role !== $resolved) {
            $this->forceFill(['role' => $resolved])->save();
        }

        return $resolved;
    }

    // ---- RBAC helpers ----------------------------------------------------

    public function hasPermission(string $permission): bool
    {
        return Rbac::roleHasPermission($this->role, $permission);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function canApprovePayments(): bool
    {
        return in_array($this->role, ['finance', 'admin'], true)
            || in_array('finance', $this->membershipRoles(), true);
    }
}

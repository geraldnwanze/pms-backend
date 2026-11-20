<?php

namespace App\Services;

use App\Mail\PropertyInvitationMail;
use App\Models\Property;
use App\Models\PropertyMember;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Property team membership + the invitation lifecycle.
 *
 * An owner (or manager) of a property invites an email address with a role.
 * The invitee — once registered with that email — sees the pending invite and
 * accepts it; only then does PropertyScope grant them the property. Declining
 * or being revoked removes the access.
 */
class MembershipService
{
    private const ROLES = ['owner', 'manager', 'agent', 'finance', 'maintenance', 'tenant'];

    public function __construct(
        private AuditService $audit,
        private NotificationService $notifications,
    ) {}

    // ---- Owner/manager side ------------------------------------------------

    public function membersOf(User $actor, string $propertyId): array
    {
        $property = Property::query()->findOrFail($propertyId);
        $this->assertCanManageTeam($actor, $property);

        return PropertyMember::query()
            ->where('propertyId', $propertyId)
            ->orderByRaw("case status when 'active' then 0 when 'invited' then 1 else 2 end")
            ->get()
            ->map(fn (PropertyMember $m) => $this->present($m))
            ->all();
    }

    public function invite(User $actor, string $propertyId, string $email, string $role): PropertyMember
    {
        $property = Property::query()->findOrFail($propertyId);
        $this->assertCanManageTeam($actor, $property);

        $email = Str::lower(trim($email));
        abort_unless(in_array($role, self::ROLES, true), 422, 'Unknown role.');
        $this->assertCanChangeOwners($actor, $property, $role);

        $existing = PropertyMember::query()
            ->where('propertyId', $propertyId)
            ->where('email', $email)
            ->where('role', $role)
            ->first();

        if ($existing && in_array($existing->status, ['invited', 'active'], true)) {
            throw ValidationException::withMessages(['email' => ["That address already has a {$role} invitation on this property."]]);
        }

        $invitee = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        $member = $existing ?? new PropertyMember(['id' => 'mem_'.Str::lower(Str::random(12))]);
        $member->forceFill([
            'propertyId' => $propertyId,
            'email' => $email,
            'role' => $role,
            'status' => 'invited',
            'userId' => $invitee?->id,
            'tenantId' => null,
            'invitedById' => $actor->id,
            'invitedAt' => now()->toIso8601String(),
            'respondedAt' => null,
        ])->save();

        $this->audit->record($actor, 'create', 'PropertyMember', $member->id, "Invited {$email} as {$role} to {$property->name}");

        // An unverified account must not learn the property, inviter or role: it only gets the email.
        if ($invitee?->hasVerifiedEmail()) {
            $this->notifications->push([
                'kind' => 'system',
                'title' => 'Property invitation',
                'body' => "{$actor->name} invited you to {$property->name} as {$role}.",
                'forUserId' => $invitee->id,
                'link' => '/invitations',
            ]);
        }

        Mail::to($email)->queue(new PropertyInvitationMail([
            'email' => $email,
            'propertyName' => $property->name,
            'role' => $role,
            'inviterName' => $actor->name,
            'acceptUrl' => FrontendUrl::to('/invitations'),
        ]));

        return $member->refresh();
    }

    public function revoke(User $actor, string $propertyId, string $memberId): void
    {
        $property = Property::query()->findOrFail($propertyId);
        $this->assertCanManageTeam($actor, $property);

        /** @var PropertyMember $member */
        $member = PropertyMember::query()->where('propertyId', $propertyId)->findOrFail($memberId);
        $this->assertCanChangeOwners($actor, $property, $member->role);

        if ($member->role === 'owner' && $member->status === 'active') {
            $owners = PropertyMember::query()
                ->where('propertyId', $propertyId)->where('role', 'owner')->where('status', 'active')->count();
            abort_if($owners <= 1, 422, 'A property must keep at least one owner.');
        }

        $member->forceFill(['status' => 'revoked', 'respondedAt' => now()->toIso8601String()])->save();
        $member->user?->recomputeRole();

        $this->audit->record($actor, 'delete', 'PropertyMember', $member->id, "Removed {$member->email} ({$member->role}) from {$property->name}");
    }

    // ---- Invitee side ---------------------------------------------------

    /**
     * @return array<int,array<string,mixed>> pending invitations for this user — none until their email is verified,
     *                                        since an invitation is addressed to an email, not to an account
     */
    public function pendingFor(User $user): array
    {
        if (! $user->hasVerifiedEmail()) {
            return [];
        }

        return $this->query($user)->where('status', 'invited')->get()
            ->map(fn (PropertyMember $m) => $this->present($m))->all();
    }

    /** @return array<int,array<string,mixed>> every membership row touching this user */
    public function allFor(User $user): array
    {
        return $this->query($user)
            ->when(! $user->hasVerifiedEmail(), fn ($q) => $q->where('status', '!=', 'invited'))
            ->get()->map(fn (PropertyMember $m) => $this->present($m))->all();
    }

    public function accept(User $user, string $memberId): PropertyMember
    {
        $this->assertVerified($user);
        $member = $this->locateForUser($user, $memberId);
        abort_unless($member->status === 'invited', 422, 'This invitation is no longer pending.');

        $tenantId = $member->tenantId;
        if ($member->role === 'tenant' && ! $tenantId) {
            $tenantId = $this->ensureTenantRecord($user, $member->propertyId);
        }

        $member->forceFill([
            'userId' => $user->id,
            'status' => 'active',
            'tenantId' => $tenantId,
            'respondedAt' => now()->toIso8601String(),
        ])->save();

        // Link the account to its tenant record so the portal resolves it.
        if ($member->role === 'tenant' && $tenantId && ! $user->tenantId) {
            $user->forceFill(['tenantId' => $tenantId])->save();
        }

        $user->recomputeRole();

        $property = Property::query()->find($member->propertyId);
        $this->audit->record($user, 'edit', 'PropertyMember', $member->id, "Accepted {$member->role} role on {$property?->name}");

        if ($member->invitedById) {
            $this->notifications->push([
                'kind' => 'system',
                'title' => 'Invitation accepted',
                'body' => "{$user->name} joined {$property?->name} as {$member->role}.",
                'forUserId' => $member->invitedById,
                'link' => "/properties/{$member->propertyId}",
            ]);
        }

        return $member->refresh();
    }

    public function decline(User $user, string $memberId): PropertyMember
    {
        $this->assertVerified($user);
        $member = $this->locateForUser($user, $memberId);
        abort_unless($member->status === 'invited', 422, 'This invitation is no longer pending.');

        $member->forceFill([
            'userId' => $user->id,
            'status' => 'declined',
            'respondedAt' => now()->toIso8601String(),
        ])->save();

        $this->audit->record($user, 'edit', 'PropertyMember', $member->id, 'Declined a property invitation');

        if ($member->invitedById) {
            $property = Property::query()->find($member->propertyId);
            $this->notifications->push([
                'kind' => 'system',
                'title' => 'Invitation declined',
                'body' => "{$user->name} declined the {$member->role} invitation to {$property?->name}.",
                'forUserId' => $member->invitedById,
                'link' => "/properties/{$member->propertyId}",
            ]);
        }

        return $member->refresh();
    }

    // ---- Internals ----------------------------------------------------

    /**
     * Set (or clear) the payout bank details on one of the user's own staff
     * memberships. Tenants have no payout, so tenant rows are rejected.
     */
    public function setBankDetails(User $user, string $memberId, array $bank): PropertyMember
    {
        $member = $this->locateForUser($user, $memberId);

        abort_if($member->role === 'tenant', 422, 'Tenants do not receive payouts.');
        abort_unless($member->status === 'active', 422, 'Accept the invitation before adding payout details.');

        $member->forceFill([
            'bankName' => $bank['bankName'] ?? null,
            'bankAccountName' => $bank['bankAccountName'] ?? null,
            'bankAccountNumber' => $bank['bankAccountNumber'] ?? null,
            'bankCode' => $bank['bankCode'] ?? null,
        ])->save();

        $this->audit->record($user, 'edit', 'PropertyMember', $member->id, 'Updated payout bank details');

        return $member->refresh();
    }

    public function presentOne(PropertyMember $m): array
    {
        return $this->present($m);
    }

    /**
     * Rows that belong to this user. The email match only applies once the address is verified: until then anyone
     * could register an address they do not own and be handed its invitations.
     */
    private function query(User $user)
    {
        return PropertyMember::query()->where(function ($q) use ($user) {
            $q->where('userId', $user->id);
            if ($user->hasVerifiedEmail()) {
                $q->orWhereRaw('lower(email) = ?', [Str::lower($user->email)]);
            }
        });
    }

    private function assertVerified(User $user): void
    {
        abort_unless($user->hasVerifiedEmail(), 403, 'Confirm your email address before responding to an invitation.');
    }

    private function locateForUser(User $user, string $memberId): PropertyMember
    {
        return $this->query($user)->findOrFail($memberId);
    }

    /** Ownership is only granted or taken away by an owner of the property or an admin — a manager can run the team, not hand out the keys. */
    private function assertCanChangeOwners(User $actor, Property $property, string $role): void
    {
        if ($role !== 'owner' || $actor->isAdmin()) {
            return;
        }

        abort_unless($actor->roleOnProperty($property->id) === 'owner', 403, 'Only an owner of this property can add or remove owners.');
    }

    private function assertCanManageTeam(User $actor, Property $property): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $role = $actor->roleOnProperty($property->id);
        abort_unless(in_array($role, ['owner', 'manager'], true), 403, 'Only an owner or manager of this property can manage its team.');
    }

    private function ensureTenantRecord(User $user, string $propertyId): string
    {
        $id = 'ten_'.Str::lower(Str::random(12));

        Tenant::query()->create([
            'id' => $id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? '',
            'identityType' => 'Not provided',
            'identityNumber' => '—',
            'emergencyContactName' => '',
            'emergencyContactPhone' => '',
            'ownerId' => Property::query()->where('id', $propertyId)->value('owners')[0]['ownerId'] ?? $user->id,
            'avatarColor' => $user->avatarColor ?? '#64748b',
            'createdAt' => now()->toIso8601String(),
        ]);

        return $id;
    }

    private function present(PropertyMember $m): array
    {
        $property = Property::query()->find($m->propertyId);
        $inviter = $m->invitedById ? User::query()->find($m->invitedById) : null;

        return [
            'id' => $m->id,
            'propertyId' => $m->propertyId,
            'propertyName' => $property?->name,
            'userId' => $m->userId,
            'email' => $m->email,
            'role' => $m->role,
            'status' => $m->status,
            'tenantId' => $m->tenantId,
            'invitedById' => $m->invitedById,
            'invitedByName' => $inviter?->name,
            'invitedAt' => $m->invitedAt,
            'respondedAt' => $m->respondedAt,
            'bankName' => $m->bankName,
            'bankAccountName' => $m->bankAccountName,
            'bankAccountNumber' => $m->bankAccountNumber,
            'bankCode' => $m->bankCode,
            'hasBankDetails' => filled($m->bankAccountNumber) && filled($m->bankName),
        ];
    }
}

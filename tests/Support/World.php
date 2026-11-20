<?php

namespace Tests\Support;

use App\Models\Building;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyMember;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A small, fully-formed portfolio for feature tests:
 *
 *   Property A  (owner A, manager A, finance A, tenant "Ada")  → building, unit, lease, one rent invoice
 *   Property B  (owner B)                                       → building, unit
 *
 * plus an admin and a registered "stranger" with no memberships.
 */
final class World
{
    public User $admin;

    public User $ownerA;

    public User $ownerB;

    public User $managerA;

    public User $financeA;

    public User $tenantUser;

    public User $stranger;

    public Property $pA;

    public Property $pB;

    public Building $bA;

    public Building $bB;

    public Unit $uA;

    public Unit $uB;

    public Tenant $tenant;

    public Lease $lease;

    public Tenant $tenantB;

    public Lease $leaseB;

    public Invoice $invoice;

    public static function build(): self
    {
        $w = new self;

        $w->admin = self::user('admin', 'admin@pms.test');
        $w->ownerA = self::user('owner', 'owner.a@pms.test');
        $w->ownerB = self::user('owner', 'owner.b@pms.test');
        $w->managerA = self::user('manager', 'manager.a@pms.test');
        $w->financeA = self::user('finance', 'finance.a@pms.test');
        $w->tenantUser = self::user('tenant', 'ada@tenant.test');
        $w->stranger = self::user('user', 'stranger@pms.test');

        $w->pA = self::property('prop_a', 'Alpha Court', $w->ownerA);
        $w->pB = self::property('prop_b', 'Beta Heights', $w->ownerB);

        self::member($w->pA, $w->ownerA, 'owner');
        self::member($w->pB, $w->ownerB, 'owner');
        self::member($w->pA, $w->managerA, 'manager');
        self::member($w->pA, $w->financeA, 'finance');

        $w->bA = Building::query()->create(['id' => 'bld_a', 'propertyId' => 'prop_a', 'name' => 'Block A', 'code' => 'BLD-A', 'floors' => 3]);
        $w->bB = Building::query()->create(['id' => 'bld_b', 'propertyId' => 'prop_b', 'name' => 'Block B', 'code' => 'BLD-B', 'floors' => 3]);

        $w->uA = self::unit('unit_a', $w->bA);
        $w->uB = self::unit('unit_b', $w->bB);

        $w->tenant = Tenant::query()->create([
            'id' => 'ten_ada', 'name' => 'Ada Obi', 'email' => 'ada@tenant.test', 'phone' => '0800000000',
            'createdAt' => now()->toDateString(),
        ]);
        self::member($w->pA, $w->tenantUser, 'tenant', $w->tenant->id);

        $w->lease = Lease::query()->create([
            'id' => 'lease_a', 'code' => 'LSE-A', 'unitId' => 'unit_a', 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada',
            'startDate' => now()->subMonths(6)->toDateString(), 'endDate' => now()->addMonths(6)->toDateString(),
            'rent' => 1_000_000, 'billingCycle' => 'annual', 'status' => 'active', 'signed' => true,
        ]);

        $w->tenantB = Tenant::query()->create([
            'id' => 'ten_bee', 'name' => 'Bee Okoro', 'email' => 'bee@tenant.test', 'phone' => '0800000001',
            'createdAt' => now()->toDateString(),
        ]);
        $w->leaseB = Lease::query()->create([
            'id' => 'lease_b', 'code' => 'LSE-B', 'unitId' => 'unit_b', 'propertyId' => 'prop_b', 'tenantId' => 'ten_bee',
            'startDate' => now()->subMonths(2)->toDateString(), 'endDate' => now()->addMonths(10)->toDateString(),
            'rent' => 800_000, 'billingCycle' => 'annual', 'status' => 'active', 'signed' => true,
        ]);

        $w->invoice = $w->invoice();

        return $w;
    }

    /** A rent invoice for Ada on property A. */
    public function invoice(array $overrides = []): Invoice
    {
        $id = $overrides['id'] ?? 'inv_'.Str::lower(Str::random(8));

        return Invoice::query()->create(array_merge([
            'id' => $id,
            'code' => 'INV-'.Str::upper(substr($id, -5)),
            'leaseId' => 'lease_a', 'unitId' => 'unit_a', 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada',
            'issueDate' => now()->subDays(20)->toDateString(),
            'dueDate' => now()->toDateString(),
            'lineItems' => [['description' => 'Rent', 'type' => 'rent', 'amount' => 100_000]],
            'total' => 100_000, 'amountPaid' => 0, 'status' => 'unpaid', 'cycle' => 'monthly',
        ], $overrides));
    }

    public static function user(string $role, string $email, bool $verified = true): User
    {
        return User::query()->create([
            'emailVerifiedAt' => $verified ? now()->toIso8601String() : null,
            'id' => 'usr_'.Str::before($email, '@'),
            'name' => Str::headline(Str::before($email, '@')),
            'email' => $email,
            'role' => $role,
            'password' => 'password',
            'avatarColor' => '#64748b',
            'status' => 'active',
        ]);
    }

    public static function member(Property $property, User $user, string $role, ?string $tenantId = null, string $status = 'active'): PropertyMember
    {
        return PropertyMember::query()->create([
            'id' => 'mem_'.Str::lower(Str::random(10)),
            'propertyId' => $property->id,
            'userId' => $status === 'invited' ? null : $user->id,
            'email' => $user->email,
            'role' => $role,
            'status' => $status,
            'tenantId' => $tenantId,
            'invitedAt' => now()->toIso8601String(),
            'respondedAt' => $status === 'invited' ? null : now()->toIso8601String(),
        ]);
    }

    private static function property(string $id, string $name, User $owner): Property
    {
        return Property::query()->create([
            'id' => $id, 'code' => strtoupper($id), 'name' => $name, 'type' => 'residential', 'status' => 'active',
            'owners' => [['ownerId' => $owner->id, 'percentage' => 100]],
            'assignedStaffIds' => [], 'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria',
            'createdAt' => now()->toDateString(),
        ]);
    }

    private static function unit(string $id, Building $building): Unit
    {
        return Unit::query()->create([
            'id' => $id, 'buildingId' => $building->id, 'propertyId' => $building->propertyId, 'code' => strtoupper($id),
            'type' => '2-Bedroom Flat', 'floor' => 1, 'status' => 'occupied', 'rent' => 1_000_000,
        ]);
    }

    public static function freezeAt(string $datetime): Carbon
    {
        Carbon::setTestNow($datetime);

        return Carbon::now();
    }
}

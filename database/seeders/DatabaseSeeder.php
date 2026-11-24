<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyMember;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Collections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Loads the generated demo dataset (database/data/seed-data.json).
 *
 * "Fresh start" membership model: the admin, the two portfolio owners, a
 * couple of maintenance technicians, one property manager and one finance
 * officer get login accounts. All property data (units, leases, invoices, …) is
 * kept — it belongs to the owners' portfolios — but the other managers, agents
 * and tenant *accounts* are not seeded. An owner rebuilds their team by inviting
 * people, who register and accept. Owner, maintenance, manager and finance
 * memberships are generated here.
 */
class DatabaseSeeder extends Seeder
{
    /** The only user accounts that survive the fresh-start seed. */
    private const KEEP_USERS = ['admin_1', 'owner_1', 'owner_2', 'finance_1', 'mgr_1', 'tech_1', 'tech_2'];

    /**
     * The day the bundled dataset was generated against (the frontend's SEED_TODAY): every date in
     * seed-data.json is relative to it. The seeder rolls them all forward so the demo is "as of today".
     */
    private const SEED_ANCHOR = '2026-08-25';

    /** Seeded maintenance staff get an active membership on every property. */
    private const MAINTENANCE_USERS = ['tech_1', 'tech_2'];

    /** The demo manager runs three of the portfolio's properties (the dataset's own assignment), so scoping is visible. */
    private const MANAGER_PROPERTIES = ['mgr_1' => ['prop_1', 'prop_3', 'prop_5']];

    /** The demo finance officer works across the whole portfolio, so every payment awaiting verification reaches them. */
    private const FINANCE_USERS = ['finance_1'];

    public function run(): void
    {
        $path = database_path('data/seed-data.json');

        if (! is_file($path)) {
            throw new RuntimeException("Seed dataset not found at {$path}. Run the frontend's `npm run dump:seed`.");
        }

        /** @var array<string, array<int, array<string, mixed>>> $data */
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $demoPassword = Hash::make('password');

        // Roll every date forward by the gap between the dataset's anchor and today, so what was
        // overdue / expiring / pending at the anchor still is today. Set SEED_FIXED_DATES=true for the
        // original fixed dates (e.g. to reproduce an exact dataset).
        $shiftDays = filter_var(env('SEED_FIXED_DATES', false), FILTER_VALIDATE_BOOLEAN)
            ? 0
            : (int) Carbon::parse(self::SEED_ANCHOR)->startOfDay()->diffInDays(now()->startOfDay(), false);
        $this->command?->info(sprintf('  dates           %s', $shiftDays === 0 ? 'fixed at '.self::SEED_ANCHOR : "rolled forward {$shiftDays} days to ".now()->toDateString()));

        Model::unguarded(function () use ($data, $demoPassword, $shiftDays) {
            DB::transaction(function () use ($data, $demoPassword, $shiftDays) {
                foreach (Collections::MAP as $key => $modelClass) {
                    $rows = $data[$key] ?? [];
                    $rows = $this->shiftDates($rows, $shiftDays);

                    if ($key === 'users') {
                        $rows = array_values(array_filter(
                            $rows,
                            fn ($u) => in_array($u['id'] ?? null, self::KEEP_USERS, true),
                        ));
                    }

                    (new $modelClass)->newQuery()->delete();

                    foreach (array_chunk($rows, 200) as $chunk) {
                        foreach ($chunk as $row) {
                            if ($key === 'users') {
                                $row['password'] = $demoPassword;
                                $row['emailVerifiedAt'] = now()->toIso8601String(); // demo accounts have no inbox to confirm from
                                $row['assignedPropertyIds'] = null;
                            }
                            if ($key === 'properties') {
                                $row['managerId'] = null;
                                $row['assignedStaffIds'] = [];
                            }
                            $modelClass::query()->create($row);
                        }
                    }

                    $this->command?->info(sprintf('  %-14s %4d rows', $key, count($rows)));
                }

                $this->seedMemberships();
                $this->seedDemoTenants();
                $this->seedDemoMember();
                $this->seedSuperAdmin();
            });
        });
    }

    /**
     * Shift every date in a row set by $days. Handles plain dates (2026-08-25) and naive datetimes
     * (2026-08-25T08:12:00), keeping the time part untouched; anything else is left alone.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function shiftDates(array $value, int $days): array
    {
        if ($days === 0) {
            return $value;
        }

        return array_map(function ($v) use ($days) {
            if (is_array($v)) {
                return $this->shiftDates($v, $days);
            }

            if (is_string($v) && preg_match('/^(\d{4}-\d{2}-\d{2})(T[\d:.]+)?$/', $v, $m)) {
                return Carbon::createFromFormat('!Y-m-d', $m[1])->addDays($days)->format('Y-m-d').($m[2] ?? '');
            }

            return $v;
        }, $value);
    }

    /**
     * Two tenant login accounts so the portal is demoable out of the box: one
     * on a monthly billing cycle, one billed annually. Each is linked to a real
     * seeded tenant record that still has an outstanding invoice.
     */
    private function seedDemoTenants(): void
    {
        $now = now()->toIso8601String();

        // Pick the lease on one of the given cycles whose outstanding invoice is
        // smallest — keeps the demo balances (and any test Paystack charge) sane.
        $pick = function (array $cycles): ?Lease {
            return Lease::query()
                ->whereIn('billingCycle', $cycles)
                ->whereIn('status', ['active', 'expiring', 'renewed'])
                ->get()
                ->map(fn (Lease $l) => [
                    'lease' => $l,
                    'outstanding' => (int) Invoice::query()
                        ->where('leaseId', $l->id)
                        ->where('status', '!=', 'cancelled')
                        ->whereColumn('amountPaid', '<', 'total')
                        ->sum(DB::raw('total - "amountPaid"')),
                ])
                ->filter(fn ($row) => $row['outstanding'] > 0)
                ->sortBy('outstanding')
                ->first()['lease'] ?? null;
        };

        $accounts = [
            ['id' => 'demo_tenant_monthly', 'email' => 'tenant@test.com', 'lease' => $pick(['monthly', 'quarterly'])],
            ['id' => 'demo_tenant_annual', 'email' => 'annual@test.com', 'lease' => $pick(['annual'])],
        ];

        foreach ($accounts as $acc) {
            $lease = $acc['lease'];
            if (! $lease) {
                continue;
            }

            $tenant = Tenant::query()->find($lease->tenantId);
            if (! $tenant) {
                continue;
            }

            User::query()->updateOrCreate(['id' => $acc['id']], [
                'name' => $tenant->name,
                'email' => $acc['email'],
                'role' => 'tenant',
                'password' => Hash::make('password'),
                'avatarColor' => $tenant->avatarColor ?? '#7c3aed',
                'status' => 'active',
                'twoFactorEnabled' => false,
                'emailVerifiedAt' => $now,
                'tenantId' => $tenant->id,
                'title' => ucfirst($lease->billingCycle).' rent',
            ]);

            PropertyMember::query()->create([
                'id' => 'mem_'.Str::lower(Str::random(12)),
                'propertyId' => $lease->propertyId,
                'userId' => $acc['id'],
                'email' => $acc['email'],
                'role' => 'tenant',
                'status' => 'active',
                'tenantId' => $tenant->id,
                'invitedById' => null,
                'invitedAt' => $now,
                'respondedAt' => $now,
            ]);
        }

        $this->command?->info('  demo tenants     '.User::query()->where('role', 'tenant')->count().' accounts');
    }

    /**
     * A freshly registered account: role `user`, no property memberships, no
     * invitations. It sees the empty "you're not on any property yet" state and
     * can register a property (becoming its owner) or accept an invitation sent
     * to member@test.com — i.e. exactly what a new sign-up experiences.
     */
    /** The one account that can switch modules on and off. Dev only: it signs in with the demo password like the rest. */
    private function seedSuperAdmin(): void
    {
        User::query()->updateOrCreate(['id' => 'superadmin_1'], [
            'name' => 'Chidi Eze',
            'email' => 'superadmin@test.com',
            'role' => 'superadmin',
            'password' => Hash::make('password'),
            'avatarColor' => '#0f766e',
            'status' => 'active',
            'twoFactorEnabled' => false,
            'emailVerifiedAt' => now()->toIso8601String(),
            'title' => 'Platform superadmin',
        ]);

        $this->command?->info('  superadmin       1 account');
    }

    private function seedDemoMember(): void
    {
        User::query()->updateOrCreate(['id' => 'demo_member'], [
            'name' => 'Amara Okafor',
            'email' => 'member@test.com',
            'role' => 'user',
            'password' => Hash::make('password'),
            'avatarColor' => '#64748b',
            'status' => 'active',
            'twoFactorEnabled' => false,
            'emailVerifiedAt' => now()->toIso8601String(),
            'title' => 'New sign-up',
        ]);

        $this->command?->info('  demo member      1 account (no properties)');
    }

    /**
     * Active memberships for the seeded accounts: one `owner` row per
     * (property, ownerId) and one `maintenance` row per tech on every property.
     */
    private function seedMemberships(): void
    {
        PropertyMember::query()->delete();

        $now = now()->toIso8601String();
        $properties = Property::query()->get();

        foreach ($properties as $property) {
            foreach ((array) $property->owners as $stake) {
                $owner = ($stake['ownerId'] ?? null) ? User::query()->find($stake['ownerId']) : null;
                if (! $owner) {
                    continue;
                }

                PropertyMember::query()->create([
                    'id' => 'mem_'.Str::lower(Str::random(12)),
                    'propertyId' => $property->id,
                    'userId' => $owner->id,
                    'email' => Str::lower($owner->email),
                    'role' => 'owner',
                    'status' => 'active',
                    'invitedById' => null,
                    'invitedAt' => $now,
                    'respondedAt' => $now,
                ]);
            }
        }

        foreach (self::MAINTENANCE_USERS as $techId) {
            $tech = User::query()->find($techId);
            if (! $tech) {
                continue;
            }

            foreach ($properties as $property) {
                PropertyMember::query()->create([
                    'id' => 'mem_'.Str::lower(Str::random(12)),
                    'propertyId' => $property->id,
                    'userId' => $tech->id,
                    'email' => Str::lower($tech->email),
                    'role' => 'maintenance',
                    'status' => 'active',
                    'invitedById' => null,
                    'invitedAt' => $now,
                    'respondedAt' => $now,
                ]);
            }
        }

        foreach (self::MANAGER_PROPERTIES as $managerId => $propertyIds) {
            foreach ($propertyIds as $propertyId) {
                $this->grant($managerId, $propertyId, 'manager', $now);
            }
        }

        foreach (self::FINANCE_USERS as $financeId) {
            foreach ($properties as $property) {
                $this->grant($financeId, $property->id, 'finance', $now);
            }
        }

        $this->command?->info('  property_members  '.PropertyMember::query()->count().' rows');
    }

    /** An active membership for a seeded account (no invitation: demo accounts have no inbox to accept from). */
    private function grant(string $userId, string $propertyId, string $role, string $now): void
    {
        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        PropertyMember::query()->create([
            'id' => 'mem_'.Str::lower(Str::random(12)),
            'propertyId' => $propertyId,
            'userId' => $user->id,
            'email' => Str::lower($user->email),
            'role' => $role,
            'status' => 'active',
            'invitedById' => null,
            'invitedAt' => $now,
            'respondedAt' => $now,
        ]);
    }
}

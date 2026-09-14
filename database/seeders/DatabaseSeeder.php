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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Loads the generated demo dataset (database/data/seed-data.json).
 *
 * "Fresh start" membership model: the admin, the two portfolio owners and a
 * couple of maintenance technicians get login accounts. All property data
 * (units, leases, invoices, …) is kept — it belongs to the owners' portfolios —
 * but managers, agents, finance and tenant *accounts* are not seeded. An owner
 * rebuilds their team by inviting people, who register and accept. Owner and
 * maintenance memberships are generated here.
 */
class DatabaseSeeder extends Seeder
{
    /** The only user accounts that survive the fresh-start seed. */
    private const KEEP_USERS = ['admin_1', 'owner_1', 'owner_2', 'tech_1', 'tech_2'];

    /** Seeded maintenance staff get an active membership on every property. */
    private const MAINTENANCE_USERS = ['tech_1', 'tech_2'];

    public function run(): void
    {
        $path = database_path('data/seed-data.json');

        if (! is_file($path)) {
            throw new RuntimeException("Seed dataset not found at {$path}. Run the frontend's `npm run dump:seed`.");
        }

        /** @var array<string, array<int, array<string, mixed>>> $data */
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $demoPassword = Hash::make('password');

        Model::unguarded(function () use ($data, $demoPassword) {
            DB::transaction(function () use ($data, $demoPassword) {
                foreach (Collections::MAP as $key => $modelClass) {
                    $rows = $data[$key] ?? [];

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
            });
        });
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

        $this->command?->info('  property_members  '.PropertyMember::query()->count().' rows');
    }
}

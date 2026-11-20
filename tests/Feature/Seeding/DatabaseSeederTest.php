<?php

namespace Tests\Feature\Seeding;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyMember;
use App\Models\Unit;
use App\Models\User;
use App\Services\DepositService;
use App\Services\LateFeeService;
use App\Services\LeaseService;
use App\Services\MetricsService;
use App\Services\StatusRefreshService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ANCHOR = '2026-08-25';

    protected function tearDown(): void
    {
        putenv('SEED_FIXED_DATES');
        unset($_ENV['SEED_FIXED_DATES'], $_SERVER['SEED_FIXED_DATES']);
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function dataset(): array
    {
        return json_decode(file_get_contents(database_path('data/seed-data.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function shift(array $value, int $days): array
    {
        $m = new ReflectionMethod(DatabaseSeeder::class, 'shiftDates');

        return $m->invoke(new DatabaseSeeder, $value, $days);
    }

    private function seedOn(string $today, bool $fixed = false): void
    {
        Carbon::setTestNow("{$today} 09:00:00");
        $fixed ? putenv('SEED_FIXED_DATES=true') : putenv('SEED_FIXED_DATES');
        $this->seed(DatabaseSeeder::class);
    }

    // ---- shiftDates ----------------------------------------------------------

    public function test_plain_dates_and_naive_datetimes_shift_and_keep_their_time(): void
    {
        $out = $this->shift(['d' => '2026-08-25', 'dt' => '2026-08-25T08:12:00', 'ms' => '2026-08-25T08:12:00.123'], 10);

        $this->assertSame('2026-09-04', $out['d']);
        $this->assertSame('2026-09-04T08:12:00', $out['dt']);
        $this->assertSame('2026-09-04T08:12:00.123', $out['ms']);
    }

    public function test_it_reaches_into_nested_structures(): void
    {
        $out = $this->shift(['lineItems' => [['due' => '2026-08-25']], 'history' => [['readAt' => '2026-08-20T10:00:00']]], 1);

        $this->assertSame('2026-08-26', $out['lineItems'][0]['due']);
        $this->assertSame('2026-08-21T10:00:00', $out['history'][0]['readAt']);
    }

    public function test_things_that_merely_look_like_dates_are_left_alone(): void
    {
        $input = [
            'id' => 'inv_2026-08-25', 'note' => 'due 2026-08-25 sharp', 'iso' => '2026-08-25T08:12:00Z',
            'tz' => '2026-08-25T08:12:00+01:00', 'slash' => '25/08/2026', 'num' => 20260825, 'null' => null, 'bool' => true, 'empty' => '',
            'month' => '2026-08', 'float' => 2026.08,
        ];

        $this->assertSame($input, $this->shift($input, 30));
    }

    public function test_zero_days_changes_nothing(): void
    {
        $input = ['d' => '2026-08-25', 'x' => ['2026-01-01T00:00:00']];

        $this->assertSame($input, $this->shift($input, 0));
    }

    public function test_it_handles_negative_shifts_month_and_year_ends_and_leap_days(): void
    {
        $this->assertSame('2026-08-15', $this->shift(['d' => '2026-08-25'], -10)['d']);
        $this->assertSame('2027-01-01', $this->shift(['d' => '2026-12-31'], 1)['d']);
        $this->assertSame('2028-02-29', $this->shift(['d' => '2028-02-28'], 1)['d']);
        $this->assertSame('2028-03-01', $this->shift(['d' => '2028-02-28'], 2)['d']);
        $this->assertSame('2025-12-31', $this->shift(['d' => '2026-01-01'], -1)['d']);
    }

    // ---- Seeding relative to today -------------------------------------------

    public function test_every_date_rolls_forward_by_the_gap_between_the_anchor_and_today(): void
    {
        $this->seedOn('2026-12-01');            // 98 days after the anchor
        $shift = Carbon::parse(self::ANCHOR)->diffInDays(Carbon::parse('2026-12-01'));
        $this->assertSame(98, (int) $shift);

        $invoices = collect($this->dataset()['invoices']);
        $this->assertGreaterThan(50, $invoices->count());
        foreach ($invoices->take(40) as $row) {
            $seeded = Invoice::query()->find($row['id']);
            $this->assertSame(Carbon::parse($row['dueDate'])->addDays(98)->toDateString(), $seeded->dueDate, $row['id']);
            $this->assertSame(Carbon::parse($row['issueDate'])->addDays(98)->toDateString(), $seeded->issueDate, $row['id']);
        }
    }

    public function test_seeded_invoices_carry_the_period_share_of_the_annual_rent_and_service_charge(): void
    {
        $this->seedOn('2026-11-12');

        $months = ['monthly' => 1, 'quarterly' => 3, 'half-yearly' => 6, 'annual' => 12];
        $invoices = Invoice::query()->get();
        $this->assertGreaterThan(50, $invoices->count());

        foreach ($invoices as $invoice) {
            $unit = Unit::query()->find($invoice->unitId);
            $m = $months[$invoice->cycle];
            $items = collect($invoice->lineItems)->keyBy('type');

            $this->assertSame((int) round($unit->rent * $m / 12), (int) $items['rent']['amount'], "{$invoice->code} rent");
            $this->assertSame((int) round($unit->serviceCharge * $m / 12), (int) ($items['service-charge']['amount'] ?? 0), "{$invoice->code} service charge");
            $this->assertSame((int) $invoice->total, (int) collect($invoice->lineItems)->sum('amount') + (int) $invoice->penalty, "{$invoice->code} total (line items plus any late fee)");
        }
    }

    public function test_seeded_late_fees_follow_the_policy_so_the_daily_job_has_nothing_left_to_charge(): void
    {
        $this->seedOn('2026-11-12');

        $this->assertGreaterThan(0, Invoice::query()->where('penalty', '>', 0)->count());
        foreach (Invoice::query()->where('penalty', '>', 0)->get() as $invoice) {
            $this->assertSame((int) round(((int) $invoice->total - (int) $invoice->penalty - (int) $invoice->amountPaid) * 0.10), (int) $invoice->penalty, $invoice->code);
            $this->assertNotNull($invoice->lateFeeAppliedOn, $invoice->code);
            $this->assertLessThanOrEqual('2026-11-12', $invoice->lateFeeAppliedOn, $invoice->code);
        }

        $report = app(LateFeeService::class)->run(true, Carbon::parse('2026-11-12'));
        $this->assertSame(0, $report['applied'], implode('; ', $report['log']));
    }

    public function test_every_seeded_lease_holds_its_deposit_in_full(): void
    {
        $this->seedOn('2026-11-12');

        $this->assertGreaterThan(50, Lease::query()->count());
        foreach (Lease::query()->get() as $lease) {
            $this->assertSame((int) $lease->securityDeposit, DepositService::heldOf($lease->depositLedger), $lease->code);
            $this->assertNull($lease->depositSettledOn, $lease->code);
        }
        $this->assertSame((int) Lease::query()->sum('securityDeposit'), (int) app(MetricsService::class)->portfolioKpis(null)['depositsHeld']);
    }

    public function test_seeded_billing_is_the_size_of_a_year_of_rent_not_twelve_times_it(): void
    {
        $this->seedOn('2026-11-12');

        $rentRoll = (int) Unit::query()->where('status', 'occupied')->sum('rent');
        $billed = (int) Invoice::query()->sum('total');

        $this->assertGreaterThan(0, $rentRoll);
        $this->assertLessThan($rentRoll * 1.5, $billed, 'one current-period invoice per lease cannot exceed the annual rent roll');
    }

    public function test_seeded_units_and_leases_agree_with_each_other(): void
    {
        $this->seedOn('2026-11-12');
        $today = '2026-11-12';
        $current = Lease::query()->whereIn('status', LeaseService::CURRENT)->get();
        $leased = $current->pluck('unitId')->all();

        $this->assertGreaterThan(50, $current->count());
        $this->assertSame(count($leased), count(array_unique($leased)), 'no unit is let to two tenants at once');

        foreach (Unit::query()->get() as $unit) {
            $this->assertSame(in_array($unit->id, $leased, true), $unit->status === 'occupied', "{$unit->code} is {$unit->status}");
        }
        foreach ($current as $lease) {
            $this->assertGreaterThanOrEqual($today, $lease->endDate, "{$lease->code} is still current after it ended");
        }
        $notOccupied = Unit::query()->where('status', '!=', 'occupied')->pluck('id')->all();
        $this->assertSame(0, Lease::query()->whereIn('unitId', $notOccupied)->count(), 'no lease on a unit that is not occupied');
    }

    public function test_seeded_reserved_units_have_a_prospective_tenant_but_no_lease_or_invoice(): void
    {
        $this->seedOn('2026-11-12');

        $reserved = Unit::query()->where('status', 'reserved')->get();
        $this->assertGreaterThan(0, $reserved->count());
        foreach ($reserved as $unit) {
            $this->assertSame(0, Lease::query()->where('unitId', $unit->id)->count(), $unit->code);
            $this->assertSame(0, Invoice::query()->where('unitId', $unit->id)->count(), $unit->code);
        }
    }

    public function test_the_dashboard_on_seeded_data_counts_as_many_occupied_units_as_active_leases(): void
    {
        $this->seedOn('2026-11-12');
        $admin = User::query()->where('role', 'admin')->firstOrFail();

        $kpis = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()->json('data.kpis');

        $this->assertSame($kpis['activeLeases'], $kpis['occupied'], 'occupancy and the lease book tell the same story');
    }

    public function test_the_daily_status_refresh_has_no_lease_to_change_on_freshly_seeded_data(): void
    {
        $this->seedOn('2026-11-12');

        $result = app(StatusRefreshService::class)->refresh(true, Carbon::parse('2026-11-12'));

        $this->assertSame([0, 0, 0], [$result['expired'], $result['expiring'], $result['reactivated']], 'the seed already agrees with the calendar');
    }

    public function test_what_was_overdue_at_the_anchor_is_still_overdue_today(): void
    {
        $data = $this->dataset();
        $overdueAtAnchor = collect($data['invoices'])
            ->filter(fn ($i) => $i['dueDate'] < self::ANCHOR && $i['amountPaid'] < $i['total'] && $i['status'] !== 'cancelled')->pluck('id')->sort()->values()->all();
        $this->assertNotEmpty($overdueAtAnchor);

        $this->seedOn('2027-03-14');
        $today = Carbon::parse('2027-03-14')->toDateString();
        $overdueNow = Invoice::query()->where('dueDate', '<', $today)->whereColumn('amountPaid', '<', 'total')->where('status', '!=', 'cancelled')
            ->pluck('id')->sort()->values()->all();

        $this->assertSame($overdueAtAnchor, $overdueNow);
    }

    public function test_lease_end_dates_keep_their_distance_from_today(): void
    {
        $data = $this->dataset();
        $row = collect($data['leases'])->first(fn ($l) => $l['status'] === 'active');
        $daysLeftAtAnchor = Carbon::parse(self::ANCHOR)->diffInDays(Carbon::parse($row['endDate']), false);

        $this->seedOn('2027-01-10');

        $daysLeftNow = Carbon::parse('2027-01-10')->diffInDays(Carbon::parse(Lease::query()->find($row['id'])->endDate), false);
        $this->assertSame((int) $daysLeftAtAnchor, (int) $daysLeftNow);
    }

    public function test_seeding_on_the_anchor_day_or_with_fixed_dates_keeps_the_original_dates(): void
    {
        $original = collect($this->dataset()['invoices'])->take(20);

        $this->seedOn('2027-06-01', fixed: true);

        foreach ($original as $row) {
            $this->assertSame($row['dueDate'], Invoice::query()->find($row['id'])->dueDate);
        }
    }

    public function test_dates_before_today_stay_before_today_and_future_ones_stay_in_the_future(): void
    {
        $this->seedOn('2027-02-02');
        $today = '2027-02-02';

        $leases = Lease::query()->get();
        $this->assertTrue($leases->every(fn ($l) => $l->startDate < $today), 'every lease has already started');
        $this->assertTrue($leases->every(fn ($l) => $l->endDate > $today), 'every lease is still running');

        $invoices = Invoice::query()->get();
        $this->assertTrue($invoices->contains(fn ($i) => $i->dueDate < $today), 'some invoices fell due in the past');
        $this->assertTrue($invoices->contains(fn ($i) => $i->dueDate > $today), 'some are still to fall due');
    }

    // ---- Who exists after seeding --------------------------------------------

    public function test_only_the_fresh_start_accounts_exist_with_the_demo_password(): void
    {
        $this->seedOn('2026-11-11');

        $ids = User::query()->pluck('id')->sort()->values()->all();
        foreach (['admin_1', 'owner_1', 'owner_2', 'tech_1', 'tech_2', 'mgr_1', 'finance_1', 'demo_member'] as $id) {
            $this->assertContains($id, $ids);
        }
        // the other managers, the agents and the ~40 tenant accounts from the dataset are NOT seeded
        foreach (['mgr_2', 'mgr_3', 'agt_1', 'tech_3', 'tuser_ten_0000g'] as $id) {
            $this->assertNotContains($id, $ids);
        }
        $this->assertLessThanOrEqual(10, count($ids));

        foreach (User::query()->get() as $user) {
            $this->assertTrue(Hash::check('password', $user->password), "{$user->id} should use the demo password");
        }
    }

    public function test_owners_are_owners_and_technicians_serve_every_property(): void
    {
        $this->seedOn('2026-11-11');
        $properties = Property::query()->pluck('id');

        foreach ($properties as $pid) {
            $ownerIds = collect(Property::query()->find($pid)->owners)->pluck('ownerId');
            foreach ($ownerIds as $oid) {
                $this->assertSame('owner', User::query()->find($oid)->roleOnProperty($pid), "owner {$oid} on {$pid}");
            }
            foreach (['tech_1', 'tech_2'] as $tech) {
                $this->assertSame('maintenance', User::query()->find($tech)->roleOnProperty($pid), "{$tech} on {$pid}");
            }
        }
        $this->assertNull(Property::query()->first()->managerId);
        $this->assertSame([], Property::query()->first()->assignedStaffIds);
    }

    public function test_the_demo_manager_runs_three_properties_and_the_finance_officer_works_across_all(): void
    {
        $this->seedOn('2026-11-11');
        $all = Property::query()->pluck('id')->sort()->values()->all();
        $manager = User::query()->find('mgr_1');
        $finance = User::query()->find('finance_1');

        $this->assertSame('manager', $manager->role);
        $this->assertSame('finance', $finance->role);
        $this->assertSame(['prop_1', 'prop_3', 'prop_5'], PropertyMember::query()->where('userId', 'mgr_1')->where('status', 'active')->pluck('propertyId')->sort()->values()->all());
        $this->assertSame($all, PropertyMember::query()->where('userId', 'finance_1')->where('role', 'finance')->where('status', 'active')->pluck('propertyId')->sort()->values()->all());
        foreach (['prop_1', 'prop_3', 'prop_5'] as $pid) {
            $this->assertSame('manager', $manager->roleOnProperty($pid), $pid);
        }
        $this->assertNull($manager->roleOnProperty('prop_2'));

        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/properties?per_page=100')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($finance, 'sanctum')->getJson('/api/v1/properties?per_page=100')->assertOk()->assertJsonCount(count($all), 'data');
    }

    public function test_the_demo_member_is_a_fresh_sign_up_with_no_access(): void
    {
        $this->seedOn('2026-11-11');

        $member = User::query()->find('demo_member');
        $this->assertSame('user', $member->role);
        $this->assertSame('member@test.com', $member->email);
        $this->assertSame(0, PropertyMember::query()->where('userId', 'demo_member')->count());

        $this->actingAs($member, 'sanctum')->getJson('/api/v1/properties')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_two_demo_tenants_are_linked_to_real_tenants_who_owe_money(): void
    {
        $this->seedOn('2026-11-11');

        foreach (['demo_tenant_monthly', 'demo_tenant_annual'] as $id) {
            $user = User::query()->find($id);
            $this->assertNotNull($user, $id);
            $this->assertSame('tenant', $user->role);
            $this->assertContains($user->tenantId, $user->tenantRecordIds());
            $this->assertTrue(
                Invoice::query()->where('tenantId', $user->tenantId)->whereColumn('amountPaid', '<', 'total')->where('status', '!=', 'cancelled')->exists(),
                "{$id} should have an outstanding invoice to pay",
            );
        }
    }

    public function test_demo_accounts_can_sign_in(): void
    {
        $this->seedOn('2026-11-11');

        foreach (['admin@pms.africa', 'owner@johnholdings.com', 'fatima.adeyemi@pms.africa', 'finance@pms.africa', 'member@test.com', 'tenant@test.com'] as $email) {
            $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password'])->assertOk()->assertJsonStructure(['data' => ['token']]);
        }
    }

    public function test_seeding_twice_replaces_rather_than_duplicates(): void
    {
        $this->seedOn('2026-11-11');
        $counts = fn () => [Invoice::query()->count(), Lease::query()->count(), Property::query()->count(), User::query()->count(), PropertyMember::query()->count()];
        $first = $counts();

        $this->seedOn('2026-11-11');

        $this->assertSame($first, $counts());
    }

    public function test_the_dataset_file_is_present_and_well_formed(): void
    {
        $data = $this->dataset();

        foreach (['users', 'properties', 'buildings', 'units', 'tenants', 'leases', 'invoices', 'payments'] as $key) {
            $this->assertNotEmpty($data[$key], $key);
        }
    }
}

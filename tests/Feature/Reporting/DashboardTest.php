<?php

namespace Tests\Feature\Reporting;

use App\Models\Expense;
use App\Models\Lead;
use App\Models\Lease;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditService;
use App\Services\StatusRefreshService;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;
use Tests\Support\World;

/**
 * The dashboard, reports and analytics numbers, against a portfolio whose figures are worked out by hand.
 *
 * Property A (Alpha Court) and property B (Beta Heights, deliberately big numbers so any leak shows).
 */
class DashboardTest extends PmsTestCase
{
    private User $tech;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');

        $this->tech = World::user('maintenance', 'tech.a@pms.test');
        World::member($this->w->pA, $this->tech, 'maintenance');
        $this->agent = World::user('agent', 'agent.a@pms.test');
        World::member($this->w->pA, $this->agent, 'agent');

        $this->w->invoice->delete();
        $this->seedPortfolio();
    }

    // ---- Fixture ------------------------------------------------------------

    private function unit(string $id, string $property, string $status, int $rent): void
    {
        Unit::query()->create(['id' => $id, 'buildingId' => $property === 'prop_a' ? 'bld_a' : 'bld_b', 'propertyId' => $property,
            'code' => strtoupper($id), 'type' => 'Flat', 'floor' => 1, 'status' => $status, 'rent' => $rent]);
    }

    private function lease(string $id, string $property, string $unit, string $tenant, string $status, string $end): void
    {
        Lease::query()->create(['id' => $id, 'code' => strtoupper($id), 'unitId' => $unit, 'propertyId' => $property, 'tenantId' => $tenant,
            'startDate' => '2025-01-01', 'endDate' => $end, 'rent' => 1, 'billingCycle' => 'annual', 'status' => $status, 'signed' => true]);
    }

    private function invoice(string $id, string $property, string $status, int $total, int $paid, string $due, string $issued = '2026-11-01'): void
    {
        $lease = $property === 'prop_a' ? 'lease_a' : 'lease_b';
        $this->w->invoice(['id' => $id, 'leaseId' => $lease, 'propertyId' => $property, 'unitId' => $property === 'prop_a' ? 'unit_a' : 'unit_b',
            'tenantId' => $property === 'prop_a' ? 'ten_ada' : 'ten_bee', 'total' => $total, 'amountPaid' => $paid, 'status' => $status,
            'dueDate' => $due, 'issueDate' => $issued]);
    }

    private function expense(string $id, string $property, string $status, int $amount, string $date = '2026-11-02', string $category = 'repairs'): void
    {
        Expense::query()->create(['id' => $id, 'code' => strtoupper($id), 'propertyId' => $property, 'category' => $category,
            'description' => $id, 'amount' => $amount, 'date' => $date, 'status' => $status]);
    }

    private function job(string $id, string $property, string $status, string $urgency, ?string $assignee = null): void
    {
        MaintenanceRequest::query()->create(['id' => $id, 'code' => strtoupper($id), 'propertyId' => $property,
            'unitId' => $property === 'prop_a' ? 'unit_a' : 'unit_b', 'tenantId' => $property === 'prop_a' ? 'ten_ada' : 'ten_bee',
            'title' => $id, 'category' => 'plumbing', 'urgency' => $urgency, 'status' => $status, 'assignedToId' => $assignee,
            'createdAt' => '2026-11-0'.(1 + (crc32($id) % 8)).'T08:00:00+00:00']);
    }

    private function payment(string $id, string $property, string $status, int $amount, string $date = '2026-11-03'): void
    {
        Payment::query()->create(['id' => $id, 'code' => strtoupper($id), 'invoiceId' => 'inv_a1', 'leaseId' => 'lease_a', 'unitId' => 'unit_a',
            'propertyId' => $property, 'tenantId' => 'ten_ada', 'type' => 'rent', 'method' => 'cash', 'amount' => $amount,
            'paymentDate' => $date, 'reference' => $id, 'status' => $status, 'submittedAt' => $date.'T10:00:00+00:00']);
    }

    private function seedPortfolio(): void
    {
        // Units — A: 2 occupied (1.0m + 0.5m), 1 available, 1 reserved, 1 maintenance, 1 renovation. B: 1 occupied, 1 available.
        $this->w->uA->forceFill(['status' => 'occupied', 'rent' => 1_000_000])->save();
        $this->w->uB->forceFill(['status' => 'occupied', 'rent' => 9_000_000])->save();
        $this->unit('ua2', 'prop_a', 'occupied', 500_000);
        $this->unit('ua3', 'prop_a', 'available', 700_000);
        $this->unit('ua4', 'prop_a', 'reserved', 600_000);
        $this->unit('ua5', 'prop_a', 'maintenance', 0);
        $this->unit('ua6', 'prop_a', 'renovation', 0);
        $this->unit('ub2', 'prop_b', 'available', 9_000_000);

        // Leases — A: three that count (active, expiring, renewed → 3 tenants), two that don't (expired, terminated).
        foreach (['ten_cy', 'ten_dee'] as $t) {
            Tenant::query()->create(['id' => $t, 'name' => $t, 'email' => "$t@t.test", 'phone' => '0', 'createdAt' => '2026-01-01']);
        }
        $this->lease('l_exp', 'prop_a', 'ua2', 'ten_cy', 'expiring', '2026-12-01');
        $this->lease('l_ren', 'prop_a', 'ua3', 'ten_dee', 'renewed', '2027-06-30');
        $this->lease('l_old', 'prop_a', 'ua4', 'ten_ada', 'expired', '2026-01-01');
        $this->lease('l_end', 'prop_a', 'ua5', 'ten_ada', 'terminated', '2026-03-01');

        // Invoices — A: billed 750k (cancelled one ignored), collected 250k, outstanding 500k, 'overdue' status on 200k.
        $this->invoice('inv_a1', 'prop_a', 'unpaid', 100_000, 0, '2026-11-15', '2026-10-05');
        $this->invoice('inv_a2', 'prop_a', 'overdue', 200_000, 0, '2026-10-20', '2026-10-10');
        $this->invoice('inv_a3', 'prop_a', 'partially-paid', 300_000, 100_000, '2026-10-01', '2026-09-20');
        $this->invoice('inv_a4', 'prop_a', 'fully-paid', 150_000, 150_000, '2026-11-10', '2026-11-01');
        $this->invoice('inv_a5', 'prop_a', 'cancelled', 999_999, 0, '2026-10-01', '2026-10-01');
        $this->invoice('inv_b1', 'prop_b', 'fully-paid', 4_000_000, 4_000_000, '2026-11-10', '2026-11-02');

        // Expenses — A counts approved + paid only (20k + 30k).
        $this->expense('exp_a1', 'prop_a', 'pending', 10_000);
        $this->expense('exp_a2', 'prop_a', 'approved', 20_000, '2026-11-05', 'security');
        $this->expense('exp_a3', 'prop_a', 'paid', 30_000, '2026-10-20');
        $this->expense('exp_a4', 'prop_a', 'rejected', 40_000);
        $this->expense('exp_b1', 'prop_b', 'paid', 5_000_000);

        // Maintenance — A: 5 open-ish (open, assigned, in-progress, completed, tenant-confirmed) + verified + closed.
        $this->job('mnt_a1', 'prop_a', 'open', 'low');
        $this->job('mnt_a2', 'prop_a', 'assigned', 'emergency', $this->tech->id);
        $this->job('mnt_a3', 'prop_a', 'in-progress', 'high', $this->tech->id);
        $this->job('mnt_a4', 'prop_a', 'completed', 'medium', $this->tech->id);
        $this->job('mnt_a5', 'prop_a', 'tenant-confirmed', 'low');
        $this->job('mnt_a6', 'prop_a', 'verified', 'low');
        $this->job('mnt_a7', 'prop_a', 'closed', 'low');
        $this->job('mnt_b1', 'prop_b', 'open', 'emergency');

        $this->payment('pay_a1', 'prop_a', 'pending-verification', 40_000);
        $this->payment('pay_a2', 'prop_a', 'pending-verification', 60_000);
        $this->payment('pay_a3', 'prop_a', 'approved', 150_000, '2026-11-04');
        $this->payment('pay_b1', 'prop_b', 'pending-verification', 7_000_000);

        Listing::query()->create(['id' => 'lst_a', 'propertyId' => 'prop_a', 'unitId' => 'ua3', 'title' => 'Flat', 'price' => 1, 'status' => 'published']);
        Listing::query()->create(['id' => 'lst_a2', 'propertyId' => 'prop_a', 'unitId' => 'ua4', 'title' => 'Flat 2', 'price' => 1, 'status' => 'draft']);
        Listing::query()->create(['id' => 'lst_b', 'propertyId' => 'prop_b', 'unitId' => 'ub2', 'title' => 'B flat', 'price' => 1, 'status' => 'published']);
        $lead = fn (string $id, ?string $prop, string $status, ?string $agent = null, ?string $viewing = null) => Lead::query()->create([
            'id' => $id, 'name' => $id, 'email' => "$id@x.test", 'phone' => '0', 'propertyId' => $prop, 'status' => $status,
            'agentId' => $agent, 'createdAt' => '2026-11-0'.(1 + (crc32($id) % 8)).'T08:00:00+00:00', 'viewingDate' => $viewing,
        ]);
        $lead('ld_1', 'prop_a', 'new', $this->agent->id);
        $lead('ld_2', 'prop_a', 'viewing-scheduled', $this->agent->id, '2026-11-20');
        $lead('ld_3', 'prop_a', 'negotiating', null, '2026-11-18');
        $lead('ld_4', 'prop_a', 'converted', $this->agent->id);
        $lead('ld_5', 'prop_a', 'lost');
        $lead('ld_6', null, 'contacted');                        // portfolio-wide lead: everyone sees it
        $lead('ld_b', 'prop_b', 'new');
    }

    private function dash(User $as, string $query = ''): array
    {
        return $this->as($as)->getJson('/api/v1/dashboard'.$query)->assertOk()->json('data');
    }

    // ---- KPIs ---------------------------------------------------------------

    public function test_the_kpis_for_one_property_add_up(): void
    {
        $k = $this->dash($this->w->ownerA)['kpis'];

        $this->assertSame(1, $k['totalProperties']);
        $this->assertSame(1, $k['totalBuildings']);
        $this->assertSame(6, $k['totalUnits']);
        $this->assertSame(2, $k['occupied']);
        $this->assertSame(1, $k['vacant']);
        $this->assertSame(1, $k['reserved']);
        $this->assertSame(1, $k['maintenance']);
        $this->assertSame(1, $k['underRenovation']);
        $this->assertEquals(50.0, $k['occupancyRate'], 'occupied ÷ (occupied + vacant + reserved) = 2/4');
        $this->assertSame(1_500_000, $k['annualRentRoll'], 'rent of occupied units only');

        $this->assertSame(3, $k['activeLeases'], 'active + expiring + renewed; expired and terminated do not count');
        $this->assertSame(3, $k['totalTenants'], 'distinct tenants on those leases');
        $this->assertSame(1, $k['expiringLeases']);

        $this->assertSame(750_000, $k['expectedBilled'], 'cancelled invoices are not billed');
        $this->assertSame(250_000, $k['collected']);
        $this->assertSame(500_000, $k['outstanding']);
        $this->assertSame(33.3, $k['collectionRate']);

        $this->assertSame(50_000, $k['totalExpenses'], 'only approved and paid expenses');
        $this->assertSame(200_000, $k['netIncome']);
        $this->assertSame(5, $k['openMaintenance'], 'everything not yet verified or closed');
        $this->assertSame(2, $k['pendingVerifications']);
    }

    public function test_an_admin_sees_the_whole_portfolio_and_each_owner_only_their_own(): void
    {
        $admin = $this->dash($this->w->admin)['kpis'];
        $a = $this->dash($this->w->ownerA)['kpis'];
        $b = $this->dash($this->w->ownerB)['kpis'];

        $this->assertSame(2, $admin['totalProperties']);
        $this->assertSame(8, $admin['totalUnits']);
        $this->assertSame(750_000 + 4_000_000, $admin['expectedBilled']);
        $this->assertSame($a['collected'] + $b['collected'], $admin['collected']);
        $this->assertSame(250_000 + 4_000_000, $admin['collected']);
        $this->assertSame(50_000 + 5_000_000, $admin['totalExpenses']);

        $this->assertSame(4_000_000, $b['collected'], 'B shows its own money');
        $this->assertSame(1, $b['totalProperties']);
        $this->assertSame(2, $b['totalUnits']);
        $this->assertSame(250_000, $a['collected'], "and A never sees B's");
    }

    public function test_the_filter_narrows_to_one_property_and_cannot_widen_beyond_access(): void
    {
        $this->assertSame(250_000, $this->dash($this->w->admin, '?propertyId=prop_a')['kpis']['collected']);
        $this->assertSame(4_000_000, $this->dash($this->w->admin, '?propertyId=prop_b')['kpis']['collected']);
        $this->assertSame(4_250_000, $this->dash($this->w->admin, '?propertyId=all')['kpis']['collected']);

        $this->assertSame(250_000, $this->dash($this->w->ownerA, '?propertyId=prop_a')['kpis']['collected']);
        $this->as($this->w->ownerA)->getJson('/api/v1/dashboard?propertyId=prop_b')->assertForbidden();
        $this->as($this->w->managerA)->getJson('/api/v1/dashboard?propertyId=prop_b')->assertForbidden();
    }

    public function test_someone_with_no_property_sees_zeroes_and_tenants_are_sent_to_the_portal(): void
    {
        $d = $this->dash($this->w->stranger);

        $this->assertSame('newcomer', $d['persona']);
        $this->assertSame(0, $d['kpis']['totalUnits']);
        $this->assertSame(0, $d['kpis']['collected']);
        $this->assertEquals(0.0, $d['kpis']['occupancyRate']);

        $this->as($this->w->tenantUser)->getJson('/api/v1/dashboard')->assertForbidden();
        $this->app['auth']->forgetGuards();
    }

    public function test_it_needs_a_login(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $this->getJson('/api/v1/analytics')->assertUnauthorized();
        $this->getJson('/api/v1/reports/collections')->assertUnauthorized();
    }

    public function test_an_empty_portfolio_produces_zeroes_not_errors(): void
    {
        $empty = User::query()->where('id', $this->w->stranger->id)->first();
        $k = $this->dash($empty)['kpis'];

        $this->assertSame(0, $k['expectedBilled']);
        $this->assertEquals(0.0, $k['collectionRate'], 'no division by zero');
    }

    public function test_overdue_figures_follow_stored_statuses_so_they_need_the_daily_refresh(): void
    {
        $this->invoice('inv_late', 'prop_a', 'unpaid', 80_000, 0, '2026-11-10', '2026-11-01');   // past due, but still marked unpaid

        $this->assertSame(200_000, $this->dash($this->w->ownerA)['kpis']['overdue']);

        app(StatusRefreshService::class)->refresh();

        $this->assertSame(280_000, $this->dash($this->w->ownerA)['kpis']['overdue'], 'once pms:refresh-statuses has run');
    }

    // ---- Personas -----------------------------------------------------------

    public function test_each_role_gets_the_persona_and_blocks_meant_for_it(): void
    {
        $expect = [
            'admin' => ['portfolio', ['unitStatus', 'occupancyByProperty', 'expiringLeases', 'overdueInvoices']],
            'ownerA' => ['portfolio', ['unitStatus', 'occupancyByProperty', 'expiringLeases', 'overdueInvoices']],
            'financeA' => ['finance', ['overdueInvoices', 'pendingPayments', 'expensesPending', 'expensesByCategory']],
            'managerA' => ['operations', ['unitStatus', 'expiringLeases', 'pendingPayments', 'maintenanceQueue']],
            'agent' => ['leasing', ['leasing']],
            'tech' => ['maintenance', ['maintenanceQueue']],
        ];

        foreach ($expect as $who => [$persona, $blocks]) {
            $user = $who === 'agent' ? $this->agent : ($who === 'tech' ? $this->tech : $this->w->{$who});
            $d = $this->dash($user);

            $this->assertSame($persona, $d['persona'], $who);
            foreach ($blocks as $block) {
                $this->assertArrayHasKey($block, $d, "{$who} should get {$block}");
            }
            $this->assertArrayNotHasKey('leasing', $persona === 'leasing' ? [] : $d, $who);
        }
    }

    public function test_the_unit_status_breakdown_and_occupancy_by_property(): void
    {
        $d = $this->dash($this->w->admin);

        $this->assertSame(['occupied' => 3, 'available' => 2, 'reserved' => 1, 'maintenance' => 1, 'renovation' => 1, 'unavailable' => 0], $d['unitStatus']);

        $rows = collect($d['occupancyByProperty'])->keyBy('propertyId');
        $this->assertSame(['units' => 6, 'occupied' => 2, 'vacant' => 1, 'occupancyRate' => 50, 'collected' => 250_000], collect($rows['prop_a'])->only(['units', 'occupied', 'vacant', 'occupancyRate', 'collected'])->all());
        $this->assertSame(['units' => 2, 'occupied' => 1, 'vacant' => 1, 'occupancyRate' => 50, 'collected' => 4_000_000], collect($rows['prop_b'])->only(['units', 'occupied', 'vacant', 'occupancyRate', 'collected'])->all());
    }

    public function test_expiring_leases_list_the_soonest_first_within_scope(): void
    {
        $this->lease('l_soon', 'prop_a', 'ua6', 'ten_dee', 'expiring', '2026-11-20');
        $this->lease('l_b', 'prop_b', 'ub2', 'ten_bee', 'expiring', '2026-11-18');

        $ids = collect($this->dash($this->w->ownerA)['expiringLeases'])->pluck('id')->all();

        $this->assertSame(['l_soon', 'l_exp'], $ids);
        $this->assertNotContains('l_b', $ids);
    }

    public function test_the_finance_blocks_list_what_needs_action(): void
    {
        $d = $this->dash($this->w->financeA);

        $this->assertSame(['inv_a2'], collect($d['overdueInvoices'])->pluck('id')->all());
        $this->assertSame(200_000, $d['overdueInvoices'][0]['outstanding']);
        $this->assertEqualsCanonicalizing(['pay_a1', 'pay_a2'], collect($d['pendingPayments'])->pluck('id')->all());
        $this->assertSame(['exp_a1'], collect($d['expensesPending'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [['category' => 'repairs', 'count' => 3, 'total' => 80_000], ['category' => 'security', 'count' => 1, 'total' => 20_000]],
            $d['expensesByCategory'],
        );
    }

    public function test_the_maintenance_queue_is_ordered_by_urgency_and_counts_every_status(): void
    {
        $q = $this->dash($this->w->managerA)['maintenanceQueue'];

        $this->assertSame(
            ['open' => 1, 'assigned' => 1, 'in-progress' => 1, 'completed' => 1, 'tenant-confirmed' => 1, 'verified' => 1, 'closed' => 1],
            $q['byStatus'],
        );
        $this->assertSame(array_sum(array_diff_key($q['byStatus'], ['verified' => 1, 'closed' => 1])), $q['openCount'], 'the buckets add up to the open count');
        $this->assertSame(5, $q['openCount']);
        $this->assertSame(['emergency' => 1, 'high' => 1, 'medium' => 1, 'low' => 4], $q['byUrgency']);
        $this->assertSame(1, $q['emergencyCount']);
        $this->assertSame(['mnt_a2', 'mnt_a3', 'mnt_a4'], array_slice(collect($q['items'])->pluck('id')->all(), 0, 3), 'emergency, high, medium first');
        $this->assertNotContains('mnt_a6', collect($q['items'])->pluck('id')->all(), 'finished work is not in the queue');
    }

    public function test_a_technician_sees_only_their_own_queue(): void
    {
        $q = $this->dash($this->tech)['maintenanceQueue'];

        $this->assertSame(3, $q['openCount']);
        $this->assertEqualsCanonicalizing(['mnt_a2', 'mnt_a3', 'mnt_a4'], collect($q['items'])->pluck('id')->all());
    }

    public function test_the_leasing_board_counts_leads_listings_and_vacancies_for_that_agent(): void
    {
        $board = $this->dash($this->agent)['leasing'];

        $this->assertSame(1, $board['liveListings'], 'published listings on this property only (the draft and B’s do not count)');
        $this->assertSame(1, $board['vacantUnits']);
        $this->assertSame(2, $board['activeLeads'], 'this agent’s new + viewing-scheduled; the converted one is done');
        $this->assertSame(1, $board['viewingsScheduled']);
        $this->assertSame(1, $board['convertedLeads']);
        $this->assertSame(['new' => 1, 'contacted' => 0, 'viewing-scheduled' => 1, 'negotiating' => 0, 'converted' => 1, 'lost' => 0], $board['leadFunnel']);
        $this->assertSame(['ua3', 'ua4'], array_column($board['availableUnits'], 'id'), 'available and reserved units, dearest first');
        $this->assertCount(3, $board['recentLeads']);
    }

    public function test_only_open_emergencies_are_flagged_as_emergencies(): void
    {
        $this->job('mnt_old', 'prop_a', 'closed', 'emergency');
        $this->job('mnt_done', 'prop_a', 'verified', 'emergency');

        $q = $this->dash($this->w->managerA)['maintenanceQueue'];

        $this->assertSame(1, $q['emergencyCount'], 'a finished emergency is not an alarm');
    }

    public function test_the_activity_feed_never_shows_another_propertys_audit_trail(): void
    {
        $audit = app(AuditService::class);
        $audit->record($this->w->ownerB, 'edit', 'Invoice', 'inv_b1', 'Beta Heights secret');
        $audit->record($this->w->managerA, 'edit', 'Invoice', 'inv_a1', 'Alpha Court visible');

        $summaries = fn (User $u) => array_column($this->dash($u)['recentActivity'], 'summary');

        $this->assertContains('Alpha Court visible', $summaries($this->w->financeA));
        $this->assertNotContains('Beta Heights secret', $summaries($this->w->financeA));
        $this->assertContains('Beta Heights secret', $summaries($this->w->admin));
        $this->assertContains('Alpha Court visible', $summaries($this->w->admin));
    }

    // ---- Reports ------------------------------------------------------------

    public function test_the_collections_report_matches_the_kpis_and_buckets_by_issue_month(): void
    {
        $r = $this->as($this->w->ownerA)->getJson('/api/v1/reports/collections?months=12')->assertOk()->json('data');

        $this->assertSame(['expectedBilled' => 750_000, 'collected' => 250_000, 'outstanding' => 500_000, 'overdue' => 200_000, 'collectionRate' => 33.3], $r['summary']);
        $this->assertSame(
            [['month' => '2026-09', 'billed' => 300_000, 'collected' => 100_000], ['month' => '2026-10', 'billed' => 300_000, 'collected' => 0], ['month' => '2026-11', 'billed' => 150_000, 'collected' => 150_000]],
            $r['series'],
        );
    }

    public function test_the_trend_keeps_only_the_most_recent_months(): void
    {
        $r = $this->as($this->w->ownerA)->getJson('/api/v1/reports/collections?months=2')->assertOk()->json('data.series');

        $this->assertSame(['2026-10', '2026-11'], array_column($r, 'month'));
    }

    public function test_the_occupancy_report_is_scoped(): void
    {
        $r = $this->as($this->w->ownerA)->getJson('/api/v1/reports/occupancy')->assertOk()->json('data');

        $this->assertSame(['totalUnits' => 6, 'occupied' => 2, 'vacant' => 1, 'reserved' => 1, 'occupancyRate' => 50], $r['portfolio']);
        $this->assertSame(['prop_a'], array_column($r['byProperty'], 'propertyId'));
    }

    public function test_the_expense_report_honours_the_date_window(): void
    {
        $this->as($this->w->financeA);

        $all = $this->getJson('/api/v1/reports/expenses')->assertOk()->json('data.byCategory');
        $this->assertSame(100_000, array_sum(array_column($all, 'total')), 'every status, this property only (the report is not limited to approved)');

        $nov = $this->getJson('/api/v1/reports/expenses?from=2026-11-01&to=2026-11-30')->assertOk()->json('data.byCategory');
        $this->assertSame(70_000, array_sum(array_column($nov, 'total')));

        $this->getJson('/api/v1/reports/expenses?from=not-a-date')->assertStatus(422);
    }

    public function test_the_owner_statement_totals_approved_collections_less_approved_and_paid_expenses(): void
    {
        $r = $this->as($this->w->ownerA)->getJson('/api/v1/reports/owner-statement?ownerId='.$this->w->ownerA->id)->assertOk()->json('data');

        $this->assertSame(150_000, $r['grossCollections'], 'only approved payments');
        $this->assertSame(50_000, $r['operatingExpenses']);
        $this->assertSame(100_000, $r['netToOwner']);
        $this->assertSame(1, $r['properties']);

        $window = $this->getJson('/api/v1/reports/owner-statement?ownerId='.$this->w->ownerA->id.'&from=2026-11-01&to=2026-11-30')->json('data');
        $this->assertSame(150_000, $window['grossCollections']);
        $this->assertSame(20_000, $window['operatingExpenses'], 'the October expense falls outside the window');
    }

    public function test_an_owner_statement_is_for_that_owner_or_an_admin_only(): void
    {
        $this->as($this->w->ownerA)->getJson('/api/v1/reports/owner-statement?ownerId='.$this->w->ownerB->id)->assertForbidden();
        $this->as($this->w->managerA)->getJson('/api/v1/reports/owner-statement?ownerId='.$this->w->ownerA->id)->assertForbidden();
        $this->as($this->w->admin)->getJson('/api/v1/reports/owner-statement?ownerId='.$this->w->ownerB->id)->assertOk()->assertJsonPath('data.grossCollections', 0);
    }

    // ---- Analytics ----------------------------------------------------------

    public function test_analytics_series_are_scoped_and_consistent_with_the_reports(): void
    {
        $d = $this->as($this->w->ownerA)->getJson('/api/v1/analytics?months=12')->assertOk()->json('data');

        $nov = collect($d['financials'])->firstWhere('month', '2026-11');
        $this->assertSame(['billed' => 150_000, 'collected' => 150_000, 'expenses' => 20_000, 'netIncome' => 130_000], collect($nov)->only(['billed', 'collected', 'expenses', 'netIncome'])->all());

        $oct = collect($d['financials'])->firstWhere('month', '2026-10');
        $this->assertSame(30_000, $oct['expenses']);

        $this->assertSame(['opened' => 7, 'closed' => 2], collect(collect($d['maintenanceByMonth'])->firstWhere('month', '2026-11'))->only(['opened', 'closed'])->all());
        $this->assertSame(1, $d['leadFunnel']['converted']);
        $this->assertSame(1, $d['leadFunnel']['new'], 'B’s lead is not counted');
    }
}

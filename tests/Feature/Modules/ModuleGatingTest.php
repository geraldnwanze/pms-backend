<?php

namespace Tests\Feature\Modules;

use App\Models\AutoDebitMandate;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Meter;
use App\Models\Notice;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;
use App\Services\AutoDebitService;
use App\Services\LateFeeService;
use App\Services\ReminderService;
use App\Services\UtilityBillingService;
use App\Support\EntityRegistry;
use App\Support\Modules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\PmsTestCase;
use Tests\Support\FakePaystack;
use Tests\Support\World;

/** Beyond the generic REST routes: a module that is off also refuses its action routes, pauses its scheduled work and trims what other screens show. */
class ModuleGatingTest extends PmsTestCase
{
    private User $superadmin;

    /** Every module-gated action route: [method, uri, module]. The REST resources are covered in ModulesTest. */
    private const ROUTES = [
        ['post', 'meters/m1/readings', 'utilities'], ['post', 'meters/bill-all', 'utilities'], ['get', 'meters/bill-runs', 'utilities'],
        ['post', 'meters/m1/bill-preview', 'utilities'], ['post', 'meters/m1/bill', 'utilities'],
        ['get', 'auto-debit', 'auto-debit'], ['post', 'auto-debit/setup', 'auto-debit'], ['delete', 'auto-debit', 'auto-debit'],
        ['get', 'auto-debit/overview', 'auto-debit'], ['get', 'auto-debit/invoices/x', 'auto-debit'],
        ['post', 'reminders/overdue', 'reminders'], ['post', 'reminders/expiring-leases', 'reminders'],
        ['post', 'maintenance/x/transition', 'maintenance'], ['post', 'maintenance/x/rounds', 'maintenance'], ['post', 'maintenance/x/confirm', 'maintenance'],
        ['get', 'maintenance-payouts', 'payouts'], ['post', 'maintenance/x/payout', 'payouts'], ['post', 'maintenance-payouts/x/approve', 'payouts'],
        ['post', 'maintenance-payouts/x/reject', 'payouts'], ['post', 'maintenance-payouts/x/pay', 'payouts'],
        ['post', 'expenses/x/status', 'expenses'], ['post', 'tasks/x/toggle', 'planner'], ['post', 'messages/mark-thread-read', 'communications'],
        ['get', 'reports/collections', 'reports'], ['get', 'reports/occupancy', 'reports'], ['get', 'reports/expenses', 'reports'],
        ['get', 'reports/owner-statement', 'reports'], ['get', 'analytics', 'reports'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->superadmin = World::user('superadmin', 'super@pms.test');
    }

    private function switchOff(string $module): void
    {
        $this->as($this->superadmin)->putJson("/api/v1/modules/{$module}", ['enabled' => false, 'cascade' => true])->assertOk();
    }

    private function meter(string $id = 'm1'): void
    {
        Meter::query()->create([
            'id' => $id, 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => 'electricity', 'meterNumber' => strtoupper($id),
            'unitOfMeasure' => 'kWh', 'previousReading' => 100, 'currentReading' => 250, 'ratePerUnit' => 60, 'history' => [],
        ]);
    }

    // ---- action routes ------------------------------------------------------------------------------------

    public function test_every_action_route_of_a_module_is_refused_while_it_is_off(): void
    {
        $this->meter();
        foreach (array_unique(array_column(self::ROUTES, 2)) as $module) {
            $this->switchOff($module);
        }

        foreach (self::ROUTES as [$method, $uri, $module]) {
            $this->as($this->superadmin)->{$method.'Json'}("/api/v1/{$uri}")
                ->assertForbidden()->assertJsonPath('module', $module);
        }
    }

    public function test_the_gated_action_routes_listed_here_are_every_one_the_router_has(): void
    {
        $rest = EntityRegistry::keys();
        $gated = [];
        foreach (Route::getRoutes() as $route) {
            $module = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'module:'));
            $uri = preg_replace('#^api/v1/#', '', $route->uri());
            $isRest = preg_match('#^([a-z-]+)(/\{id\})?$#', $uri, $m) && in_array($m[1], $rest, true);
            if (! $module || $isRest) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $gated[] = strtolower($method).' '.preg_replace('/\{[a-z]+\}/', 'x', $uri).' '.substr($module, 7);
            }
        }
        $listed = array_map(fn ($r) => "{$r[0]} ".str_replace('/m1', '/x', $r[1]).' '.$r[2], self::ROUTES);

        $this->assertEqualsCanonicalizing($listed, $gated, 'a gated route is missing from (or extra in) ROUTES');
    }

    public function test_the_action_routes_work_again_when_the_module_is_back_on(): void
    {
        $this->meter();
        $this->switchOff('utilities');
        $this->as($this->w->managerA)->getJson('/api/v1/meters/bill-runs')->assertForbidden();

        $this->as($this->superadmin)->putJson('/api/v1/modules/utilities', ['enabled' => true])->assertOk();

        $this->as($this->w->managerA)->getJson('/api/v1/meters/bill-runs')->assertOk();
        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/bill-preview')->assertOk()->assertJsonPath('data.ok', true);
    }

    public function test_an_unrelated_module_being_off_does_not_touch_these_routes(): void
    {
        $this->switchOff('planner');
        $this->switchOff('reports');

        $this->assertNull($this->as($this->w->managerA)->getJson('/api/v1/auto-debit')->json('module'), 'not refused as a module that is off');
        $this->as($this->w->managerA)->postJson('/api/v1/reminders/overdue')->assertOk();
    }

    public function test_payout_routes_report_themselves_off_when_the_maintenance_they_need_is_off(): void
    {
        $this->switchOff('maintenance');

        $this->as($this->w->managerA)->getJson('/api/v1/maintenance-payouts')
            ->assertForbidden()->assertJsonPath('module', 'payouts')->assertJsonPath('message', 'The Technician payouts module is turned off.');
    }

    // ---- scheduled commands -------------------------------------------------------------------------------

    public function test_the_monthly_utility_billing_does_nothing_while_utilities_is_off_and_bills_once_it_is_on(): void
    {
        Invoice::query()->delete();
        Lease::query()->where('id', 'lease_b')->delete();
        Lease::query()->where('id', 'lease_a')->update(['startDate' => '2026-01-01', 'endDate' => '2027-01-01', 'status' => 'active']);
        $this->meter();
        $this->switchOff('utilities');

        $code = Artisan::call('pms:bill-utilities');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Skipped: the Utilities & meters module is turned off.', Artisan::output());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertNull(Meter::query()->find('m1')->billedReading);

        $this->as($this->superadmin)->putJson('/api/v1/modules/utilities', ['enabled' => true])->assertOk();
        // (a new application instance per command run would also re-read the switch; here the scoped instance is reset by the request above)
        Artisan::call('pms:bill-utilities');
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_the_other_scheduled_commands_skip_their_work_while_their_module_is_off(): void
    {
        $this->switchOff('auto-debit');
        $this->switchOff('reminders');
        // A mock with no expectations fails the test if the command so much as calls the service.
        $this->mock(AutoDebitService::class);
        $this->mock(LateFeeService::class);
        $this->mock(ReminderService::class);

        foreach (['pms:auto-debit' => 'Auto-debit', 'pms:apply-late-fees' => 'Late fees & reminders', 'pms:lease-expiry-reminders' => 'Late fees & reminders'] as $command => $label) {
            $this->assertSame(0, Artisan::call($command), $command);
            $this->assertStringContainsString("Skipped: the {$label} module is turned off.", Artisan::output(), $command);
        }
    }

    public function test_rent_reminders_are_not_sent_while_reminders_is_off(): void
    {
        $this->w->invoice->update(['dueDate' => '2026-11-06']);
        $this->switchOff('reminders');

        $this->assertSame(0, Artisan::call('pms:rent-reminders'));

        $this->assertStringContainsString('Skipped', Artisan::output());
        $this->assertSame(0, Notification::query()->where('title', 'Rent overdue')->count());

        $this->as($this->superadmin)->putJson('/api/v1/modules/reminders', ['enabled' => true])->assertOk();
        Artisan::call('pms:rent-reminders');
        $this->assertSame(1, Notification::query()->where('title', 'Rent overdue')->where('forUserId', 'ten_ada')->count());
    }

    public function test_invoice_generation_and_status_refresh_are_core_and_keep_running(): void
    {
        foreach (Modules::keys() as $key) {
            $this->switchOff($key);
        }

        $this->assertSame(0, Artisan::call('pms:generate-invoices'));
        $this->assertSame(0, Artisan::call('pms:refresh-statuses'));
        $this->assertStringNotContainsString('Skipped', Artisan::output());
    }

    // ---- flows that cross into another module --------------------------------------------------------------

    public function test_ending_a_tenancy_with_utilities_off_refuses_closing_readings_and_changes_nothing(): void
    {
        $this->meter();
        $this->switchOff('utilities');

        $this->as($this->w->managerA)->postJson('/api/v1/leases/lease_a/terminate', ['closingReadings' => [['meterId' => 'm1', 'value' => 300]]])
            ->assertUnprocessable()->assertJsonPath('message', 'The Utilities & meters module is turned off, so closing readings cannot be taken.');

        $this->assertSame('active', Lease::query()->find('lease_a')->status);
        $this->assertSame(250.0, Meter::query()->find('m1')->currentReading);
    }

    public function test_ending_a_tenancy_with_utilities_off_still_ends_it_but_raises_no_final_bill(): void
    {
        Invoice::query()->delete();
        $this->meter();
        $this->switchOff('utilities');

        $this->as($this->w->managerA)->postJson('/api/v1/leases/lease_a/terminate')
            ->assertOk()->assertJsonPath('meta.finalBill', null);

        $this->assertSame('terminated', Lease::query()->find('lease_a')->status);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertNull(Meter::query()->find('m1')->billedReading, 'the use stays unbilled');
    }

    public function test_the_final_bill_for_an_expired_lease_is_not_raised_while_utilities_is_off(): void
    {
        Invoice::query()->delete();
        $this->meter();
        $lease = Lease::query()->find('lease_a');
        $lease->update(['status' => 'expired', 'startDate' => '2025-01-01', 'endDate' => '2026-11-10']);

        $this->switchOff('utilities');
        $this->assertNull(app(UtilityBillingService::class)->billFinal('system', $lease->refresh()));
        $this->assertSame(0, Invoice::query()->count());

        $this->as($this->superadmin)->putJson('/api/v1/modules/utilities', ['enabled' => true])->assertOk();
        $this->assertNotNull(app(UtilityBillingService::class)->billFinal('system', $lease->refresh()), 'and is raised once the module is back on');
    }

    public function test_ending_a_tenancy_with_utilities_on_still_takes_the_closing_readings(): void
    {
        $this->meter();

        $this->as($this->w->managerA)->postJson('/api/v1/leases/lease_a/terminate', ['closingReadings' => [['meterId' => 'm1', 'value' => 300]]])->assertOk();

        $this->assertSame(300.0, Meter::query()->find('m1')->currentReading);
    }

    private function payInvoiceByCardAndAskToSaveIt(string $invoiceId): void
    {
        $id = $this->as($this->w->tenantUser)->postJson('/api/v1/payments/paystack/initialize', ['invoiceId' => $invoiceId, 'saveCard' => true])
            ->assertOk()->json('data.payment.id');
        $payment = Payment::query()->findOrFail($id);
        $this->paystack->transaction($payment->gatewayReference, FakePaystack::cardTransaction($payment->gatewayReference, 100_000 * 100));

        $this->as($this->w->tenantUser)->postJson('/api/v1/payments/paystack/verify', ['reference' => $payment->gatewayReference])->assertOk();
    }

    public function test_a_card_is_saved_for_auto_debit_only_while_auto_debit_is_on(): void
    {
        $second = $this->w->invoice(['id' => 'inv_second', 'dueDate' => '2026-12-01']);

        $this->payInvoiceByCardAndAskToSaveIt($this->w->invoice->id);
        $this->assertSame(1, AutoDebitMandate::query()->count(), 'on: the card the tenant asked to keep is kept');

        AutoDebitMandate::query()->delete();
        $this->switchOff('auto-debit');

        $this->payInvoiceByCardAndAskToSaveIt($second->id);

        $this->assertSame(0, AutoDebitMandate::query()->count(), 'off: the payment goes through but no card is kept');
        $this->assertSame(100_000, (int) $second->fresh()->amountPaid);
    }

    // ---- what other screens show ----------------------------------------------------------------------------

    public function test_the_operations_dashboard_shows_no_maintenance_queue_while_maintenance_is_off(): void
    {
        MaintenanceRequest::query()->create(['id' => 'mnt_a', 'code' => 'MNT-A', 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'tenantId' => 'ten_ada',
            'title' => 'Leak', 'category' => 'plumbing', 'urgency' => 'high', 'status' => 'open', 'createdAt' => '2026-11-10T08:00:00+00:00']);
        $this->assertContains('mnt_a', array_column($this->as($this->w->managerA)->getJson('/api/v1/dashboard')->assertOk()->json('data.maintenanceQueue.items'), 'id'));

        $this->switchOff('maintenance');

        $queue = $this->as($this->w->managerA)->getJson('/api/v1/dashboard')->assertOk()->json('data.maintenanceQueue');
        $this->assertSame([], $queue['items']);
        $this->assertSame(0, $queue['openCount']);
        $this->assertSame(0, array_sum($queue['byStatus']));
        $this->assertSame(0, array_sum($queue['byUrgency']));
    }

    public function test_the_finance_dashboard_shows_no_expenses_while_expenses_is_off(): void
    {
        Expense::query()->create(['id' => 'exp_a', 'code' => 'E-A', 'propertyId' => 'prop_a', 'category' => 'repairs', 'description' => 'd', 'amount' => 5000, 'date' => '2026-11-01', 'status' => 'pending']);
        $this->assertCount(1, $this->as($this->w->financeA)->getJson('/api/v1/dashboard')->json('data.expensesPending'));

        $this->switchOff('expenses');

        $on = $this->as($this->w->financeA)->getJson('/api/v1/dashboard')->assertOk();
        $this->assertSame([], $on->json('data.expensesPending'));
        $this->assertSame([], $on->json('data.expensesByCategory'));
        $this->assertNotEmpty($on->json('data.kpis'), 'the rest of the dashboard is untouched');
    }

    public function test_the_leasing_dashboard_keeps_vacant_units_but_shows_no_leads_or_listings_while_leasing_is_off(): void
    {
        $agent = World::user('agent', 'agent.a@pms.test');
        World::member($this->w->pA, $agent, 'agent');
        Lead::query()->create(['id' => 'ld_1', 'name' => 'Lead', 'email' => 'l@x.test', 'phone' => '0', 'propertyId' => 'prop_a', 'status' => 'new', 'agentId' => $agent->id, 'createdAt' => '2026-11-05T08:00:00+00:00']);
        $this->assertSame(1, $this->as($agent)->getJson('/api/v1/dashboard')->assertOk()->json('data.leasing.activeLeads'));

        $this->switchOff('leasing');

        $board = $this->as($agent)->getJson('/api/v1/dashboard')->assertOk()->json('data.leasing');
        $this->assertSame(0, $board['activeLeads']);
        $this->assertSame([], $board['recentLeads']);
        $this->assertSame(0, array_sum($board['leadFunnel']));
        $this->assertArrayHasKey('vacantUnits', $board);
    }

    public function test_the_tenant_portal_shows_no_requests_or_notices_while_those_modules_are_off(): void
    {
        MaintenanceRequest::query()->create(['id' => 'mnt_a', 'code' => 'MNT-A', 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'tenantId' => 'ten_ada',
            'title' => 'Leak', 'category' => 'plumbing', 'urgency' => 'high', 'status' => 'open', 'createdAt' => '2026-11-10T08:00:00+00:00']);
        Notice::query()->create(['id' => 'n1', 'title' => 'Water off', 'body' => 'Tuesday', 'propertyId' => null, 'audience' => 'all', 'createdAt' => '2026-11-10T08:00:00+00:00', 'createdById' => $this->w->managerA->id]);
        $portal = $this->as($this->w->tenantUser)->getJson('/api/v1/portal')->assertOk();
        $this->assertSame(1, $portal->json('data.openRequestsCount'));
        $this->assertCount(1, $portal->json('data.notices'));

        $this->switchOff('maintenance');
        $this->switchOff('communications');

        $portal = $this->as($this->w->tenantUser)->getJson('/api/v1/portal')->assertOk();
        $this->assertSame(0, $portal->json('data.openRequestsCount'));
        $this->assertSame([], $portal->json('data.recentRequests'));
        $this->assertSame([], $portal->json('data.notices'));
        $this->assertGreaterThan(0, $portal->json('data.balance'), 'the invoices are core and still show');
    }

    public function test_analytics_leaves_out_the_series_of_modules_that_are_off(): void
    {
        MaintenanceRequest::query()->create(['id' => 'mnt_a', 'code' => 'MNT-A', 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'tenantId' => 'ten_ada',
            'title' => 'Leak', 'category' => 'plumbing', 'urgency' => 'high', 'status' => 'open', 'createdAt' => '2026-11-10T08:00:00+00:00']);
        Lead::query()->create(['id' => 'ld_1', 'name' => 'Lead', 'email' => 'l@x.test', 'phone' => '0', 'propertyId' => 'prop_a', 'status' => 'new', 'createdAt' => '2026-11-05T08:00:00+00:00']);
        $on = $this->as($this->superadmin)->getJson('/api/v1/analytics')->assertOk();
        $this->assertCount(1, $on->json('data.maintenanceByMonth'));
        $this->assertSame(1, $on->json('data.leadFunnel.new'));

        $this->switchOff('maintenance');
        $this->switchOff('leasing');
        $this->switchOff('expenses');
        $this->switchOff('reports');
        // (analytics itself is the Reports module, so it is refused; the trimming matters when Reports is on and the others are off)
        $this->as($this->superadmin)->getJson('/api/v1/analytics')->assertForbidden();
        $this->as($this->superadmin)->putJson('/api/v1/modules/reports', ['enabled' => true])->assertOk();

        $off = $this->as($this->superadmin)->getJson('/api/v1/analytics')->assertOk();
        $this->assertSame([], $off->json('data.maintenanceByMonth'));
        $this->assertSame(0, $off->json('data.leadFunnel.new'));
        $this->assertSame([], $off->json('data.expensesByCategory'));
        $this->assertNotEmpty($off->json('data.financials'));
    }
}

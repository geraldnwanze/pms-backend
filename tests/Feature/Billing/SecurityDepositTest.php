<?php

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Notification;
use App\Services\MetricsService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\PmsTestCase;

/** The security deposit: collected against the lease, held in trust, and settled once when the tenancy ends. */
class SecurityDepositTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 09:00:00');
        Lease::query()->where('id', 'lease_a')->update(['securityDeposit' => 500_000]);
    }

    private function url(string $path = '', string $lease = 'lease_a'): string
    {
        return "/api/v1/leases/{$lease}/deposit{$path}";
    }

    private function receive(int $amount, string $as = 'managerA'): TestResponse
    {
        return $this->as($this->w->{$as})->postJson($this->url('/receive'), ['amount' => $amount, 'method' => 'cash']);
    }

    private function end(string $status = 'terminated'): void
    {
        Lease::query()->where('id', 'lease_a')->update(['status' => $status]);
    }

    public function test_a_new_lease_shows_what_is_required_and_nothing_held(): void
    {
        $this->as($this->w->managerA)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.required', 500_000)->assertJsonPath('data.received', 0)
            ->assertJsonPath('data.outstanding', 500_000)->assertJsonPath('data.held', 0)->assertJsonPath('data.ledger', []);
    }

    public function test_receiving_in_part_and_then_the_rest(): void
    {
        $this->receive(200_000)->assertCreated()->assertJsonPath('data.held', 200_000)->assertJsonPath('data.outstanding', 300_000);
        $this->receive(300_000, 'financeA')->assertCreated()->assertJsonPath('data.held', 500_000)->assertJsonPath('data.outstanding', 0);

        $this->assertCount(2, Lease::query()->find('lease_a')->depositLedger);
    }

    public function test_more_than_the_deposit_asks_for_is_refused(): void
    {
        $this->receive(200_000);

        $this->receive(300_001)->assertUnprocessable();
        $this->receive(300_000)->assertCreated();
        $this->receive(1)->assertUnprocessable()->assertJsonFragment(['message' => 'The full deposit has already been received.']);
        $this->assertSame(500_000, $this->w->lease->refresh()->depositLedger ? array_sum(array_column($this->w->lease->depositLedger, 'amount')) : 0);
    }

    public function test_a_lease_without_a_deposit_or_not_current_cannot_receive_one(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['securityDeposit' => 0]);
        $this->receive(1_000)->assertUnprocessable();

        Lease::query()->where('id', 'lease_a')->update(['securityDeposit' => 500_000]);
        $this->end();
        $this->receive(1_000)->assertUnprocessable();
    }

    public function test_the_amount_must_be_a_positive_whole_number_and_the_method_known(): void
    {
        $this->as($this->w->managerA);
        $this->postJson($this->url('/receive'), ['amount' => 0, 'method' => 'cash'])->assertUnprocessable();
        $this->postJson($this->url('/receive'), ['amount' => -5, 'method' => 'cash'])->assertUnprocessable();
        $this->postJson($this->url('/receive'), ['amount' => 100.5, 'method' => 'cash'])->assertUnprocessable();
        $this->postJson($this->url('/receive'), ['amount' => 100, 'method' => 'bitcoin'])->assertUnprocessable();
    }

    public function test_receiving_tells_the_tenant_and_is_audited(): void
    {
        $this->receive(100_000);

        $this->assertTrue(Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Security deposit received')->exists());
        $this->assertTrue(AuditLog::query()->where('entityId', 'lease_a')->where('summary', 'like', 'Received%deposit%')->exists());
    }

    // ---- settling ------------------------------------------------------------------------

    public function test_settling_keeps_the_deductions_and_refunds_the_rest(): void
    {
        $this->receive(500_000);
        $this->end();

        $this->as($this->w->financeA)->postJson($this->url('/settle'), ['deductions' => [
            ['reason' => 'Broken window', 'amount' => 80_000], ['reason' => 'Unpaid water bill', 'amount' => 20_000],
        ]])->assertOk()->assertJsonPath('data.held', 0)->assertJsonPath('data.settledOn', '2026-11-18');

        $types = collect(Lease::query()->find('lease_a')->depositLedger)->groupBy('type')->map(fn ($g) => $g->sum('amount'));
        $this->assertSame(100_000, $types['deduction']);
        $this->assertSame(400_000, $types['refund']);
        $this->assertTrue(Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Security deposit settled')->exists());
    }

    public function test_settling_with_no_deductions_refunds_it_all(): void
    {
        $this->receive(500_000);
        $this->end('expired');

        $this->as($this->w->managerA)->postJson($this->url('/settle'), [])->assertOk()->assertJsonPath('data.held', 0);

        $this->assertSame(500_000, (int) collect(Lease::query()->find('lease_a')->depositLedger)->where('type', 'refund')->sum('amount'));
    }

    public function test_keeping_all_of_it_writes_no_empty_refund(): void
    {
        $this->receive(100_000);
        $this->end();

        $this->as($this->w->managerA)->postJson($this->url('/settle'), ['deductions' => [['reason' => 'Full repaint', 'amount' => 100_000]]])->assertOk()->assertJsonPath('data.held', 0);

        $this->assertSame(['received', 'deduction'], array_column(Lease::query()->find('lease_a')->depositLedger, 'type'));
    }

    public function test_a_lease_with_a_deposit_can_be_deleted_once_none_is_held(): void
    {
        Invoice::query()->delete();

        $this->as($this->w->admin)->deleteJson('/api/v1/leases/lease_a')->assertSuccessful();
    }

    public function test_deductions_cannot_exceed_what_is_held(): void
    {
        $this->receive(100_000);
        $this->end();

        $this->as($this->w->managerA)->postJson($this->url('/settle'), ['deductions' => [['reason' => 'Repainting', 'amount' => 100_001]]])->assertUnprocessable();

        $this->assertNull(Lease::query()->find('lease_a')->depositSettledOn);
        $this->assertSame(100_000, $this->w->lease->refresh()->depositLedger[0]['amount']);
    }

    public function test_a_deposit_is_settled_only_once_and_only_after_the_lease_has_ended(): void
    {
        $this->receive(100_000);
        $this->as($this->w->managerA)->postJson($this->url('/settle'), [])->assertUnprocessable();       // still current

        $this->end();
        $this->as($this->w->managerA)->postJson($this->url('/settle'), [])->assertOk();
        $this->as($this->w->managerA)->postJson($this->url('/settle'), [])->assertUnprocessable()->assertJsonFragment(['message' => 'This deposit has already been settled.']);
    }

    public function test_settling_needs_money_held_and_a_reason_for_each_deduction(): void
    {
        $this->end();
        $this->as($this->w->managerA)->postJson($this->url('/settle'), [])->assertUnprocessable();         // nothing held

        Lease::query()->where('id', 'lease_a')->update(['status' => 'active']);
        $this->receive(100_000);
        $this->end();
        $this->as($this->w->managerA)->postJson($this->url('/settle'), ['deductions' => [['reason' => '', 'amount' => 10]]])->assertUnprocessable();
        $this->as($this->w->managerA)->postJson($this->url('/settle'), ['deductions' => [['reason' => 'Paint', 'amount' => 0]]])->assertUnprocessable();
    }

    public function test_the_summary_suggests_the_move_out_inspections_deductions_to_staff_only(): void
    {
        Inspection::query()->create(['id' => 'ins_out', 'code' => 'INS-O', 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'tenantId' => 'ten_ada', 'type' => 'move-out', 'status' => 'completed', 'scheduledDate' => '2026-11-10', 'completedDate' => '2026-11-10', 'items' => [], 'depositDeductions' => 45_000]);

        $this->as($this->w->managerA)->getJson($this->url())->assertJsonPath('data.inspectionDeductions', 45_000);
        $this->as($this->w->tenantUser)->getJson($this->url())->assertOk()->assertJsonPath('data.inspectionDeductions', null);
    }

    // ---- who ------------------------------------------------------------------------------

    public function test_only_manager_finance_and_admin_move_the_money(): void
    {
        foreach (['ownerA', 'tenantUser', 'ownerB', 'stranger'] as $who) {
            $this->receive(1_000, $who)->assertForbidden();
        }
        $this->assertNull(Lease::query()->find('lease_a')->depositLedger);

        $this->receive(1_000, 'admin')->assertCreated();
    }

    public function test_the_tenant_and_owner_can_read_but_other_properties_cannot(): void
    {
        $this->receive(100_000);

        $this->as($this->w->tenantUser)->getJson($this->url())->assertOk()->assertJsonPath('data.held', 100_000);
        $this->as($this->w->ownerA)->getJson($this->url())->assertOk();
        $this->as($this->w->ownerB)->getJson($this->url())->assertForbidden();
        $this->as($this->w->stranger)->getJson($this->url())->assertForbidden();
        $this->getJson($this->url('', 'nope'))->assertNotFound();
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->postJson($this->url('/receive'), ['amount' => 1, 'method' => 'cash'])->assertUnauthorized();
        $this->postJson($this->url('/settle'), [])->assertUnauthorized();
    }

    // ---- the lease around it ---------------------------------------------------------------

    public function test_the_ledger_cannot_be_written_through_the_lease_endpoints(): void
    {
        $this->as($this->w->managerA)->patchJson('/api/v1/leases/lease_a', ['depositLedger' => [['type' => 'received', 'amount' => 999]], 'depositSettledOn' => '2026-01-01', 'notes' => 'x'])->assertOk();

        $lease = Lease::query()->find('lease_a');
        $this->assertNull($lease->depositLedger);
        $this->assertNull($lease->depositSettledOn);
    }

    public function test_the_required_deposit_cannot_be_cut_below_what_was_received(): void
    {
        $this->receive(300_000);

        $this->as($this->w->managerA)->patchJson('/api/v1/leases/lease_a', ['securityDeposit' => 299_999])->assertUnprocessable();
        $this->as($this->w->managerA)->patchJson('/api/v1/leases/lease_a', ['securityDeposit' => 300_000])->assertOk();
        $this->as($this->w->managerA)->patchJson('/api/v1/leases/lease_a', ['securityDeposit' => 800_000])->assertOk();
    }

    public function test_a_lease_holding_a_deposit_cannot_be_deleted(): void
    {
        Invoice::query()->delete();
        Lease::query()->where('id', 'lease_a')->update(['depositLedger' => [['id' => 'd', 'type' => 'received', 'amount' => 5, 'date' => '2026-01-01', 'note' => '', 'byId' => 'x']]]);

        $this->as($this->w->admin)->deleteJson('/api/v1/leases/lease_a')->assertUnprocessable();
    }

    public function test_deposits_held_is_reported_apart_from_revenue(): void
    {
        $this->receive(250_000);

        $kpis = app(MetricsService::class)->portfolioKpis(null);

        $this->assertSame(250_000, $kpis['depositsHeld']);
        $this->assertSame(0, $kpis['collected']);
    }
}

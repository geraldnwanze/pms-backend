<?php

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Notification;
use App\Models\Tenant;
use App\Services\StatusRefreshService;
use App\Services\UtilityBillingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\PmsTestCase;

/** A tenant who leaves pays for the use they leave behind: billed when the tenancy ends, and for readings taken at move-out. */
class FinalBillTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        Invoice::query()->delete();
        Lease::query()->where('id', 'lease_b')->delete();
        Lease::query()->where('id', 'lease_a')->update(['startDate' => '2026-01-01', 'endDate' => '2027-01-01', 'status' => 'active']);

        $this->meter('m1');                                                                                    // 150 × ₦60 = ₦9,000
        $this->meter('m2', ['type' => 'water', 'unitOfMeasure' => 'm³', 'previousReading' => 10, 'currentReading' => 20.5, 'ratePerUnit' => 200]);   // ₦2,100
    }

    private function meter(string $id, array $over = []): Meter
    {
        return Meter::query()->create($over + [
            'id' => $id, 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => 'electricity', 'meterNumber' => strtoupper($id),
            'unitOfMeasure' => 'kWh', 'previousReading' => 100, 'currentReading' => 250, 'ratePerUnit' => 60, 'history' => [],
        ]);
    }

    private function terminate()
    {
        return $this->as($this->w->managerA)->postJson('/api/v1/leases/lease_a/terminate');
    }

    private function newTenantOnUnit(string $start = '2026-11-10'): void
    {
        Tenant::query()->create(['id' => 'ten_new', 'name' => 'Nia New', 'email' => 'nia@tenant.test', 'phone' => '0800000009', 'createdAt' => '2026-11-01']);
        Lease::query()->create([
            'id' => 'lease_new', 'code' => 'LSE-NEW', 'unitId' => 'unit_a', 'propertyId' => 'prop_a', 'tenantId' => 'ten_new',
            'startDate' => $start, 'endDate' => '2027-11-09', 'rent' => 1_000_000, 'billingCycle' => 'annual', 'status' => 'active', 'signed' => true,
        ]);
    }

    // ---- when the lease is terminated ---------------------------------------------------------------------------

    public function test_terminating_a_lease_bills_the_leaving_tenant_for_the_use_still_unbilled(): void
    {
        $this->terminate()->assertOk();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('ten_ada', $invoice->tenantId);
        $this->assertSame('lease_a', $invoice->leaseId);
        $this->assertSame(11_100, $invoice->total);                               // both meters, on one invoice
        $this->assertCount(2, $invoice->lineItems);
        $this->assertSame('2026-11-29', $invoice->dueDate);
        $this->assertSame(250.0, Meter::query()->find('m1')->billedReading);       // the markers moved: nothing is billed twice
        $this->assertSame(20.5, Meter::query()->find('m2')->billedReading);
        $this->assertSame('2026-11-15', Lease::query()->find('lease_a')->terminatedOn);
    }

    public function test_the_leaving_tenant_is_told_it_is_their_final_bill_and_it_is_on_record(): void
    {
        $this->terminate()->assertOk();

        $invoice = Invoice::query()->firstOrFail();
        $notice = Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Final utility bill')->firstOrFail();
        $this->assertSame("Your tenancy has ended. Invoice {$invoice->code} for ₦11,100 covers your last utility use and is due 29 Nov 2026.", $notice->body);
        $this->assertSame("/portal/bills/{$invoice->id}", $notice->link);
        $this->assertTrue(AuditLog::query()->where('summary', 'like', "Billed utilities on {$invoice->code}%final bill after lease LSE-A ended")->exists());
    }

    public function test_nothing_is_billed_when_nothing_is_waiting_but_the_lease_still_ends(): void
    {
        Meter::query()->where('id', 'm1')->update(['billedReading' => 250]);
        Meter::query()->where('id', 'm2')->update(['billedReading' => 20.5]);

        $this->terminate()->assertOk();

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame('terminated', Lease::query()->find('lease_a')->status);
    }

    public function test_nothing_is_billed_to_the_leaver_if_someone_else_already_holds_the_unit(): void
    {
        $this->newTenantOnUnit('2026-11-01');                                      // the next tenant is already in

        $this->terminate()->assertOk();

        $this->assertSame(0, Invoice::query()->count());                           // their use and the leaver's cannot be told apart
        $this->assertNull(Meter::query()->find('m1')->billedReading);
        $this->assertSame('terminated', Lease::query()->find('lease_a')->status);
    }

    public function test_a_billing_failure_never_stops_the_lease_from_being_terminated(): void
    {
        Exceptions::fake();
        Event::listen('eloquent.creating: '.Invoice::class, fn () => throw new RuntimeException('boom'));

        $this->terminate()->assertOk();

        $this->assertSame('terminated', Lease::query()->find('lease_a')->status);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertNull(Meter::query()->find('m1')->billedReading);              // rolled back whole: a later bill still gets it
        Exceptions::assertReported(RuntimeException::class);
    }

    // ---- when the lease runs out --------------------------------------------------------------------------------

    public function test_a_lease_that_runs_out_is_final_billed_by_the_daily_status_job(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['endDate' => '2026-11-10']);

        $r = app(StatusRefreshService::class)->refresh(false, Carbon::parse('2026-11-15'));

        $this->assertSame([1, 1], [$r['expired'], $r['finalBills']]);
        $this->assertSame('expired', Lease::query()->find('lease_a')->status);
        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame(['ten_ada', 11_100], [$invoice->tenantId, $invoice->total]);
    }

    public function test_a_dry_run_of_the_status_job_bills_nothing(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['endDate' => '2026-11-10']);

        $r = app(StatusRefreshService::class)->refresh(true, Carbon::parse('2026-11-15'));

        $this->assertSame([1, 0], [$r['expired'], $r['finalBills']]);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame('active', Lease::query()->find('lease_a')->status);
    }

    // ---- readings taken after the tenant has gone ---------------------------------------------------------------

    private function endTenancyWithNothingLeftToBill(): void
    {
        $this->terminate()->assertOk();
        Invoice::query()->delete();
        Notification::query()->delete();
    }

    public function test_a_reading_taken_at_move_out_after_the_lease_ended_is_billed_to_the_tenant_who_left(): void
    {
        $this->endTenancyWithNothingLeftToBill();
        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/readings', ['value' => 300, 'date' => '2026-11-15'])->assertCreated();   // 50 kWh since

        $r = app(UtilityBillingService::class)->run();

        $this->assertSame(1, $r['invoices']);
        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame(['ten_ada', 'lease_a', 3_000], [$invoice->tenantId, $invoice->leaseId, $invoice->total]);
        $this->assertTrue(AuditLog::query()->where('summary', 'like', '%final bill after lease LSE-A ended')->exists());
    }

    public function test_staff_can_bill_that_use_by_hand_and_the_bulk_preview_marks_it_as_a_final_bill(): void
    {
        $this->endTenancyWithNothingLeftToBill();
        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/readings', ['value' => 300, 'date' => '2026-11-15'])->assertCreated();

        $preview = $this->as($this->w->managerA)->postJson('/api/v1/meters/bill-all', ['dryRun' => true])->assertOk()->json('data');
        $this->assertSame(1, count($preview['units']));
        $this->assertTrue($preview['units'][0]['final']);
        $this->assertSame('Ada Obi', $preview['units'][0]['tenantName']);
        $this->assertSame([], $preview['skipped']);                               // not "no tenant": the leaver is billed

        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/bill')->assertCreated()->assertJsonPath('data.total', 3_000)->assertJsonPath('data.tenantId', 'ten_ada');
    }

    public function test_a_unit_with_a_current_tenant_is_not_marked_final(): void
    {
        $preview = $this->as($this->w->managerA)->postJson('/api/v1/meters/bill-all', ['dryRun' => true])->assertOk()->json('data');

        $this->assertFalse($preview['units'][0]['final']);
    }

    public function test_the_new_tenant_is_billed_for_use_after_they_moved_in_not_the_old_one(): void
    {
        $this->endTenancyWithNothingLeftToBill();
        $this->newTenantOnUnit('2026-11-10');
        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/readings', ['value' => 300, 'date' => '2026-11-15'])->assertCreated();

        app(UtilityBillingService::class)->run();

        $this->assertSame('ten_new', Invoice::query()->firstOrFail()->tenantId);
    }

    // ---- how long the leaver stays responsible -----------------------------------------------------------------

    public function test_the_leaver_is_billed_for_sixty_days_and_after_that_the_use_is_the_landlords(): void
    {
        foreach ([['2026-09-16', true], ['2026-09-15', false]] as [$terminatedOn, $billed]) {     // 60 and 61 days before the 15th of November
            Invoice::query()->delete();
            Meter::query()->update(['billedReading' => 100, 'billedAt' => null]);
            Lease::query()->where('id', 'lease_a')->update(['status' => 'terminated', 'terminatedOn' => $terminatedOn]);

            $r = app(UtilityBillingService::class)->run();

            $this->assertSame($billed ? 1 : 0, $r['invoices'], "ended {$terminatedOn}");
        }

        $this->assertSame(250.0, Meter::query()->find('m1')->billedReading);       // past the window the job writes it off, as for any vacant unit
    }

    public function test_a_terminated_lease_with_no_termination_date_and_a_future_end_is_not_billed_for(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['status' => 'terminated', 'terminatedOn' => null, 'endDate' => '2027-01-01']);

        $r = app(UtilityBillingService::class)->run();

        $this->assertSame(0, $r['invoices']);                                       // we cannot tell when they left
    }

    public function test_an_older_terminated_lease_counts_from_its_end_date_when_that_has_passed(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['status' => 'terminated', 'terminatedOn' => null, 'endDate' => '2026-11-01']);

        $r = app(UtilityBillingService::class)->run();

        $this->assertSame(1, $r['invoices']);
    }

    public function test_a_lease_that_has_not_ended_is_not_a_final_bill(): void
    {
        $lease = Lease::query()->find('lease_a');

        $this->assertNull($lease->endedOn(Carbon::parse('2026-11-15')));
        $this->assertTrue($lease->holdsUnitOn(Carbon::parse('2026-11-15')));
        $this->assertNull(app(UtilityBillingService::class)->billFinal('system', $lease));        // asked too early: nothing happens
        $this->assertSame(0, Invoice::query()->count());
    }

    // ---- what happens next --------------------------------------------------------------------------------------

    public function test_the_final_bill_is_there_to_be_paid_from_the_deposit_when_it_is_settled(): void
    {
        Lease::query()->where('id', 'lease_a')->update([
            'securityDeposit' => 500_000,
            'depositLedger' => [['id' => 'dep_1', 'type' => 'received', 'amount' => 500_000, 'date' => '2026-01-01', 'note' => 'Deposit', 'byId' => 'x']],
        ]);

        $this->terminate()->assertOk();
        $this->as($this->w->managerA)->postJson('/api/v1/leases/lease_a/deposit/settle', ['deductions' => [], 'applyToArrears' => true])->assertOk();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame(['fully-paid', 11_100], [$invoice->status, (int) $invoice->amountPaid]);
        $ledger = collect(Lease::query()->find('lease_a')->depositLedger);
        $this->assertSame(11_100, (int) $ledger->where('type', 'offset')->sum('amount'));
        $this->assertSame(488_900, (int) $ledger->where('type', 'refund')->sum('amount'));      // what is left goes back to the tenant
    }

    public function test_billing_twice_never_bills_the_same_use_twice(): void
    {
        $this->terminate()->assertOk();

        $this->assertSame(0, app(UtilityBillingService::class)->run()['invoices']);
        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/bill')->assertUnprocessable();
        $this->assertSame(1, Invoice::query()->count());
    }
}

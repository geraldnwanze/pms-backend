<?php

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Notification;
use App\Services\InvoiceGenerationService;
use App\Services\UtilityBillingService;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;

/** Meter readings become invoices: the right units, at the right rate, to the right tenant, exactly once. */
class UtilityBillingTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        Invoice::query()->delete();
        Lease::query()->where('id', 'lease_b')->delete();
    }

    private function meter(string $id, array $over = []): Meter
    {
        return Meter::query()->create($over + [
            'id' => $id, 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => 'electricity', 'meterNumber' => strtoupper($id),
            'unitOfMeasure' => 'kWh', 'previousReading' => 100, 'currentReading' => 250, 'ratePerUnit' => 60, 'history' => [],
        ]);
    }

    private function bill(bool $dry = false): array
    {
        return app(UtilityBillingService::class)->run($dry);
    }

    public function test_use_since_the_previous_reading_is_billed_at_the_meters_rate(): void
    {
        $m = $this->meter('m1');

        $r = $this->bill();

        $this->assertSame(1, $r['invoices']);
        $inv = Invoice::query()->firstOrFail();
        $this->assertSame(9_000, $inv->total);                  // 150 kWh × ₦60
        $this->assertSame('utility', $inv->lineItems[0]['type']);
        $this->assertSame(150.0, (float) $inv->lineItems[0]['units']);
        $this->assertSame('ten_ada', $inv->tenantId);
        $this->assertSame('lease_a', $inv->leaseId);
        $this->assertSame('2026-11-29', $inv->dueDate);
        $this->assertNull($inv->periodStart);
        $this->assertSame('custom', $inv->cycle);
        $this->assertSame(250.0, $m->refresh()->billedReading);
        $this->assertSame(0.0, $m->unbilledUnits);
    }

    public function test_a_reading_is_never_billed_twice_and_later_use_is_billed_from_where_it_left_off(): void
    {
        $this->meter('m1');
        $this->bill();
        $this->assertSame(0, $this->bill()['invoices']);

        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/readings', ['value' => 300, 'date' => '2026-12-01'])->assertCreated();
        $this->assertSame(3_000, Meter::query()->find('m1')->unbilledAmount);      // 50 × 60

        $this->bill();

        $this->assertSame([9_000, 3_000], Invoice::query()->orderBy('issueDate')->orderBy('total', 'desc')->pluck('total')->all());
    }

    public function test_two_readings_between_bills_are_billed_together(): void
    {
        $this->meter('m1');
        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/readings', ['value' => 260, 'date' => '2026-11-10'])->assertCreated();
        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/readings', ['value' => 280, 'date' => '2026-11-14'])->assertCreated();

        $this->bill();

        $this->assertSame(10_800, Invoice::query()->firstOrFail()->total);          // 180 × 60, measured from the billed 100
    }

    public function test_a_units_meters_share_one_invoice(): void
    {
        $this->meter('m1');
        $this->meter('m2', ['type' => 'water', 'unitOfMeasure' => 'm³', 'previousReading' => 10, 'currentReading' => 20.5, 'ratePerUnit' => 200]);

        $this->bill();

        $this->assertSame(1, Invoice::query()->count());
        $inv = Invoice::query()->firstOrFail();
        $this->assertCount(2, $inv->lineItems);
        $this->assertSame(9_000 + 2_100, $inv->total);
    }

    public function test_an_unpriced_or_backwards_meter_is_skipped_and_reported_not_billed(): void
    {
        $this->meter('m1', ['ratePerUnit' => null]);
        $this->meter('m2', ['previousReading' => 500, 'currentReading' => 400]);

        $r = $this->bill();

        $this->assertSame(0, $r['invoices']);
        $this->assertCount(2, $r['skipped']);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertNull(Meter::query()->find('m1')->billedReading);
    }

    public function test_a_vacant_unit_is_not_billed_and_its_use_is_not_left_for_the_next_tenant(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['status' => 'terminated']);
        $m = $this->meter('m1');

        $r = $this->bill();

        $this->assertSame(0, Invoice::query()->count());
        $this->assertCount(1, $r['skipped']);
        $this->assertSame(250.0, $m->refresh()->billedReading);
    }

    public function test_a_lease_not_yet_started_or_already_ended_is_not_billed(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['startDate' => '2026-12-01', 'endDate' => '2027-11-30']);
        $this->meter('m1');

        $this->bill();

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $m = $this->meter('m1');

        $r = $this->bill(true);

        $this->assertSame(9_000, $r['total']);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertNull($m->refresh()->billedReading);
    }

    public function test_the_tenant_is_told_and_it_is_audited_as_the_system(): void
    {
        $this->meter('m1');

        $this->bill();

        $this->assertTrue(Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Utility bill issued')->exists());
        $this->assertTrue(AuditLog::query()->where('summary', 'like', 'Billed utilities%')->exists());
    }

    public function test_the_notice_gives_a_readable_due_date_and_opens_the_invoice_itself(): void
    {
        $this->meter('m1');

        $this->bill();

        $invoice = Invoice::query()->firstOrFail();
        $notice = Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Utility bill issued')->firstOrFail();

        $this->assertSame("Invoice {$invoice->code} for ₦9,000 (utilities) is due 29 Nov 2026.", $notice->body);      // not "2026-11-29"
        $this->assertSame("/portal/bills/{$invoice->id}", $notice->link);
    }

    public function test_billing_one_meter_by_hand_sends_the_same_notice(): void
    {
        $this->meter('m1');

        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/bill')->assertCreated();

        $invoice = Invoice::query()->firstOrFail();
        $notice = Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Utility bill issued')->firstOrFail();
        $this->assertStringContainsString('is due 29 Nov 2026.', $notice->body);
        $this->assertSame("/portal/bills/{$invoice->id}", $notice->link);
    }

    public function test_the_command_works_and_refuses_the_date_switch_in_production(): void
    {
        $this->meter('m1');

        $this->artisan('pms:bill-utilities')->assertSuccessful();
        $this->assertSame(1, Invoice::query()->count());

        $this->app['env'] = 'production';
        $this->artisan('pms:bill-utilities', ['--date' => '2027-01-01'])->assertFailed();
    }

    public function test_a_utility_invoice_does_not_stand_in_for_the_rent_invoice(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['billingCycle' => 'monthly', 'startDate' => '2026-06-01', 'endDate' => '2027-05-31']);
        $this->meter('m1');
        $this->bill();

        $r = app(InvoiceGenerationService::class)->run();

        $this->assertSame(1, $r['created']);
        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_a_hand_made_rent_invoice_still_covers_its_period(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['billingCycle' => 'monthly', 'startDate' => '2026-06-01', 'endDate' => '2027-05-31']);
        $this->w->invoice(['dueDate' => '2026-11-10']);

        $this->assertSame(0, app(InvoiceGenerationService::class)->run()['created']);
    }

    // ---- by hand ----------------------------------------------------------------------

    public function test_a_manager_can_bill_one_meter_now(): void
    {
        $this->meter('m1');

        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/bill')->assertCreated()->assertJsonPath('data.total', 9_000);

        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/bill')->assertUnprocessable();    // nothing left to bill
    }

    public function test_billing_a_meter_by_hand_explains_why_it_cannot(): void
    {
        $this->meter('m1', ['ratePerUnit' => 0]);
        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/bill')->assertUnprocessable()->assertJsonFragment(['message' => 'M1: no rate is set, so its use cannot be priced.']);

        Meter::query()->where('id', 'm1')->update(['ratePerUnit' => 60]);
        Lease::query()->where('id', 'lease_a')->update(['status' => 'terminated']);
        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/bill')->assertUnprocessable();
        $this->assertSame(0, Invoice::query()->count());
        $this->assertNull(Meter::query()->find('m1')->billedReading);       // a refused manual bill moves nothing
    }

    public function test_only_people_who_manage_the_property_can_bill_its_meters(): void
    {
        $this->meter('m1');

        $this->as($this->w->tenantUser)->postJson('/api/v1/meters/m1/bill')->assertForbidden();
        $this->as($this->w->ownerB)->postJson('/api/v1/meters/m1/bill')->assertForbidden();
        $this->as($this->w->stranger)->postJson('/api/v1/meters/m1/bill')->assertForbidden();
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_the_meter_shows_what_is_waiting_to_be_billed(): void
    {
        $this->meter('m1');

        $this->as($this->w->managerA)->getJson('/api/v1/meters')->assertOk()
            ->assertJsonPath('data.0.unbilledUnits', 150)->assertJsonPath('data.0.unbilledAmount', 9_000);
    }

    public function test_the_billing_marker_cannot_be_set_from_outside(): void
    {
        $m = $this->meter('m1');

        $this->as($this->w->managerA)->patchJson('/api/v1/meters/m1', ['billedReading' => 999, 'notes' => 'x'])->assertStatus(200);

        $this->assertNull($m->refresh()->billedReading);
    }

    public function test_a_lease_that_ended_yesterday_but_is_not_yet_marked_over_is_billed_to_the_tenant_who_just_left(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['startDate' => '2025-11-14', 'endDate' => '2026-11-14']);
        $m = $this->meter('m1');

        $this->bill();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('ten_ada', $invoice->tenantId);            // their last use is theirs, not the landlord's (see FinalBillTest)
        $this->assertSame(9_000, $invoice->total);
        $this->assertSame(250.0, $m->refresh()->billedReading);
    }
}

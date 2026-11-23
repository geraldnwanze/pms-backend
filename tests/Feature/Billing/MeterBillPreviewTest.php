<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;

/** The preview staff see before billing a meter comes from the server, so it can never disagree with the invoice. */
class MeterBillPreviewTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        Invoice::query()->delete();
        Lease::query()->where('id', 'lease_b')->delete();
        Lease::query()->where('id', 'lease_a')->update(['startDate' => '2026-01-01', 'endDate' => '2027-01-01', 'status' => 'active']);
        $this->meter('m1');
    }

    private function meter(string $id, array $over = []): Meter
    {
        return Meter::query()->create($over + [
            'id' => $id, 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => 'electricity', 'meterNumber' => strtoupper($id),
            'unitOfMeasure' => 'kWh', 'previousReading' => 100, 'currentReading' => 250, 'ratePerUnit' => 60, 'history' => [],
        ]);
    }

    private function preview(array $body = [], string $meter = 'm1', $as = null)
    {
        return $this->as($as ?? $this->w->managerA)->postJson("/api/v1/meters/{$meter}/bill-preview", $body);
    }

    public function test_it_shows_what_billing_would_raise_and_bills_nothing(): void
    {
        $this->preview()->assertOk()->assertJson(['data' => [
            'ok' => true, 'tenantName' => 'Ada Obi', 'leaseCode' => $this->w->lease->code, 'issueDate' => '2026-11-15', 'dueDate' => '2026-11-29',
            'from' => 100, 'to' => 250, 'units' => 150, 'rate' => 60, 'total' => 9_000, 'final' => false, 'endedOn' => null,
        ]]);

        $this->assertSame(0, Invoice::query()->count());
        $this->assertNull(Meter::query()->find('m1')->billedReading);
    }

    public function test_it_agrees_with_the_invoice_that_billing_then_raises(): void
    {
        $p = $this->preview()->json('data');

        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/bill')->assertCreated();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame($p['total'], $invoice->total);
        $this->assertSame($p['dueDate'], $invoice->dueDate);
        $this->assertSame($p['issueDate'], $invoice->issueDate);
        $this->assertSame('ten_ada', $invoice->tenantId);
    }

    public function test_a_typed_reading_is_priced_as_if_it_had_been_logged(): void
    {
        $this->preview(['reading' => 300])->assertOk()->assertJsonPath('data.to', 300)->assertJsonPath('data.units', 200)->assertJsonPath('data.total', 12_000);

        $this->assertSame(250.0, Meter::query()->find('m1')->currentReading);       // nothing was logged
    }

    public function test_a_typed_reading_below_the_current_one_is_explained(): void
    {
        $this->preview(['reading' => 200])->assertOk()->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.reason', 'The reading (200) is lower than the current reading (250).');
    }

    public function test_it_says_why_a_meter_cannot_be_billed(): void
    {
        Meter::query()->where('id', 'm1')->update(['billedReading' => 250]);
        $this->preview()->assertJsonPath('data.ok', false)->assertJsonPath('data.reason', 'Nothing to bill: no use has been read since the last bill.');

        $this->meter('m_free', ['ratePerUnit' => 0]);
        $this->preview([], 'm_free')->assertJsonPath('data.reason', 'No rate is set, so its use cannot be priced.');

        $this->meter('m_low', ['billedReading' => 400]);
        $this->preview([], 'm_low')->assertJsonPath('data.reason', 'The reading (250) is lower than what was last billed (400); check the meter.');

        $this->meter('m_common', ['unitId' => null]);
        $this->preview([], 'm_common')->assertJsonPath('data.reason', 'This meter is not attached to a unit, so there is no tenant to bill.');
    }

    public function test_a_vacant_unit_has_nobody_to_bill(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['status' => 'terminated', 'terminatedOn' => '2026-08-01']);   // left over 60 days ago

        $this->preview()->assertJsonPath('data.ok', false)->assertJsonPath('data.reason', 'This unit has no tenant at the moment, so there is nobody to bill.');
    }

    public function test_just_after_a_tenant_leaves_it_is_their_final_bill(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['status' => 'terminated', 'terminatedOn' => '2026-11-10']);

        $this->preview()->assertJsonPath('data.ok', true)->assertJsonPath('data.final', true)->assertJsonPath('data.endedOn', '2026-11-10')->assertJsonPath('data.tenantName', 'Ada Obi');
    }

    public function test_the_final_bill_window_is_sixty_days_and_not_a_day_longer(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['status' => 'terminated', 'terminatedOn' => '2026-09-16']);                 // 60 days ago
        $this->preview()->assertJsonPath('data.ok', true)->assertJsonPath('data.final', true);

        Lease::query()->where('id', 'lease_a')->update(['terminatedOn' => '2026-09-15']);                                          // 61
        $this->preview()->assertJsonPath('data.ok', false);
    }

    public function test_whoever_holds_the_unit_now_is_preferred_to_someone_who_left_earlier(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['status' => 'terminated', 'terminatedOn' => '2026-11-10']);
        Tenant::query()->create(['id' => 'ten_new', 'name' => 'Nia New', 'email' => 'nia@tenant.test', 'phone' => '0800000009', 'createdAt' => '2026-11-01']);
        Lease::query()->create([
            'id' => 'lease_new', 'code' => 'LSE-NEW', 'unitId' => 'unit_a', 'propertyId' => 'prop_a', 'tenantId' => 'ten_new',
            'startDate' => '2026-11-12', 'endDate' => '2027-11-11', 'rent' => 1_000_000, 'billingCycle' => 'annual', 'status' => 'active', 'signed' => true,
        ]);

        $this->preview()->assertJsonPath('data.tenantName', 'Nia New')->assertJsonPath('data.leaseCode', 'LSE-NEW')->assertJsonPath('data.final', false);
    }

    public function test_the_preview_and_the_final_bill_at_termination_name_the_same_tenant_and_total(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['status' => 'terminated', 'terminatedOn' => '2026-11-10']);
        $p = $this->preview()->json('data');

        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/bill')->assertCreated();

        $this->assertSame($p['total'], Invoice::query()->firstOrFail()->total);
        $this->assertSame('ten_ada', Invoice::query()->firstOrFail()->tenantId);
    }

    public function test_only_people_who_may_bill_the_meter_can_preview_it(): void
    {
        foreach (['tenantUser', 'ownerB', 'stranger', 'financeA'] as $who) {
            $this->preview([], 'm1', $this->w->{$who})->assertForbidden();
        }
    }

    public function test_the_request_is_validated(): void
    {
        $this->preview(['reading' => 'lots'])->assertUnprocessable();
        $this->preview(['reading' => -3])->assertUnprocessable();
        $this->preview([], 'nope')->assertNotFound();
    }
}

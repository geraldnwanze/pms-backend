<?php

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;

/** The readings taken at move-out are saved in the same step that ends the tenancy, so the final bill is raised on them. */
class ClosingReadingsTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        Invoice::query()->delete();
        Lease::query()->where('id', 'lease_b')->delete();
        Lease::query()->where('id', 'lease_a')->update(['startDate' => '2026-01-01', 'endDate' => '2027-01-01', 'status' => 'active']);

        // Billed from the reading before the last one: m1 has 150 kWh waiting (₦9,000), m2 has 10 m³ (₦2,000).
        $this->meter('m1');
        $this->meter('m2', ['type' => 'water', 'unitOfMeasure' => 'm³', 'previousReading' => 10, 'currentReading' => 20, 'ratePerUnit' => 200]);
    }

    private function meter(string $id, array $over = []): Meter
    {
        return Meter::query()->create($over + [
            'id' => $id, 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => 'electricity', 'meterNumber' => strtoupper($id),
            'unitOfMeasure' => 'kWh', 'previousReading' => 100, 'currentReading' => 250, 'ratePerUnit' => 60, 'history' => [],
        ]);
    }

    private function terminate(array $body = [], $as = null)
    {
        return $this->as($as ?? $this->w->managerA)->postJson('/api/v1/leases/lease_a/terminate', $body);
    }

    /** Nothing at all changed: the lease still runs, no reading was logged, nothing was billed. */
    private function assertNothingChanged(): void
    {
        $lease = Lease::query()->find('lease_a');
        $this->assertSame('active', $lease->status);
        $this->assertNull($lease->terminatedOn);
        $this->assertSame(250.0, Meter::query()->find('m1')->currentReading);
        $this->assertSame(20.0, Meter::query()->find('m2')->currentReading);
        $this->assertSame([], Meter::query()->find('m1')->history);
        $this->assertSame([], Meter::query()->find('m2')->history);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_the_closing_readings_are_logged_and_the_final_bill_is_raised_on_them(): void
    {
        $r = $this->terminate(['closingReadings' => [['meterId' => 'm1', 'value' => 300], ['meterId' => 'm2', 'value' => 24]]])->assertOk();

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame(12_000 + 2_800, $invoice->total);                          // 200 kWh × ₦60 and 14 m³ × ₦200: from where each was last billed to the closing reading
        $this->assertSame('ten_ada', $invoice->tenantId);
        $this->assertSame(300.0, Meter::query()->find('m1')->billedReading);
        $this->assertSame(24.0, Meter::query()->find('m2')->billedReading);
        $this->assertSame('terminated', Lease::query()->find('lease_a')->status);
        $r->assertJsonPath('meta.finalBill.id', $invoice->id)->assertJsonPath('meta.finalBill.code', $invoice->code)->assertJsonPath('meta.finalBill.total', 14_800);
    }

    public function test_each_closing_reading_goes_into_the_meters_history_dated_today(): void
    {
        $this->terminate(['closingReadings' => [['meterId' => 'm1', 'value' => 300]]])->assertOk();

        $m1 = Meter::query()->find('m1');
        $this->assertSame([['date' => '2026-11-15', 'value' => 300.0]], array_map(fn ($h) => ['date' => $h['date'], 'value' => (float) $h['value']], $m1->history));
        $this->assertSame([250.0, 300.0], [$m1->previousReading, $m1->currentReading]);
    }

    public function test_a_closing_reading_equal_to_the_current_one_adds_nothing_to_the_history(): void
    {
        $this->terminate(['closingReadings' => [['meterId' => 'm1', 'value' => 250]]])->assertOk();

        $this->assertSame([], Meter::query()->find('m1')->history);
        $this->assertSame(9_000 + 2_000, Invoice::query()->firstOrFail()->total);       // what was already waiting is still billed
    }

    public function test_the_audit_trail_says_how_many_meters_had_closing_readings(): void
    {
        $this->terminate(['closingReadings' => [['meterId' => 'm1', 'value' => 300], ['meterId' => 'm2', 'value' => 24]]])->assertOk();

        $this->assertTrue(AuditLog::query()->where('summary', 'Terminated lease LSE-A (closing readings logged for 2 meters)')->exists());
    }

    public function test_without_closing_readings_it_works_as_before_and_bills_what_is_waiting(): void
    {
        $r = $this->terminate()->assertOk();

        $this->assertSame(11_000, Invoice::query()->firstOrFail()->total);
        $r->assertJsonPath('meta.finalBill.total', 11_000);
        $this->assertTrue(AuditLog::query()->where('summary', 'Terminated lease LSE-A')->exists());
    }

    // ---- nothing is half done ----------------------------------------------------------------------------------

    public function test_a_closing_reading_below_the_current_one_is_refused_and_nothing_changes(): void
    {
        // The first reading is fine; the second is not. Neither is applied.
        $this->terminate(['closingReadings' => [['meterId' => 'm1', 'value' => 300], ['meterId' => 'm2', 'value' => 15]]])
            ->assertUnprocessable()->assertJsonFragment(['message' => 'M2: the closing reading (15) is lower than the current reading (20).']);

        $this->assertNothingChanged();
    }

    public function test_a_meter_from_another_unit_is_refused(): void
    {
        $this->meter('m_other', ['unitId' => 'unit_b', 'propertyId' => 'prop_b']);

        $this->terminate(['closingReadings' => [['meterId' => 'm1', 'value' => 300], ['meterId' => 'm_other', 'value' => 999]]])
            ->assertUnprocessable()->assertJsonFragment(['message' => "That meter is not on this lease's unit."]);

        $this->assertNothingChanged();
        $this->assertSame(250.0, Meter::query()->find('m_other')->currentReading);
    }

    public function test_an_unknown_meter_is_refused(): void
    {
        $this->terminate(['closingReadings' => [['meterId' => 'nope', 'value' => 5]]])->assertUnprocessable();

        $this->assertNothingChanged();
    }

    public function test_the_request_is_validated(): void
    {
        $this->terminate(['closingReadings' => 'm1'])->assertUnprocessable();
        $this->terminate(['closingReadings' => [['meterId' => 'm1']]])->assertUnprocessable();                                   // no value
        $this->terminate(['closingReadings' => [['meterId' => 'm1', 'value' => -5]]])->assertUnprocessable();                   // negative
        $this->terminate(['closingReadings' => [['meterId' => 'm1', 'value' => 300], ['meterId' => 'm1', 'value' => 310]]])->assertUnprocessable();   // the same meter twice

        $this->assertNothingChanged();
    }

    public function test_someone_who_may_not_end_the_lease_cannot_use_it_to_change_meters(): void
    {
        foreach (['tenantUser', 'ownerB', 'stranger'] as $who) {
            $this->terminate(['closingReadings' => [['meterId' => 'm1', 'value' => 300]]], $this->w->{$who})->assertForbidden();
        }

        $this->assertNothingChanged();
    }

    // ---- when the unit has someone else in it --------------------------------------------------------------------

    public function test_if_the_next_tenant_is_already_in_the_readings_are_kept_but_no_final_bill_is_raised(): void
    {
        Tenant::query()->create(['id' => 'ten_new', 'name' => 'Nia New', 'email' => 'nia@tenant.test', 'phone' => '0800000009', 'createdAt' => '2026-11-01']);
        Lease::query()->create([
            'id' => 'lease_new', 'code' => 'LSE-NEW', 'unitId' => 'unit_a', 'propertyId' => 'prop_a', 'tenantId' => 'ten_new',
            'startDate' => '2026-11-01', 'endDate' => '2027-10-31', 'rent' => 1_000_000, 'billingCycle' => 'annual', 'status' => 'active', 'signed' => true,
        ]);

        $r = $this->terminate(['closingReadings' => [['meterId' => 'm1', 'value' => 300]]])->assertOk();

        $this->assertSame(0, Invoice::query()->count());
        $r->assertJsonPath('meta.finalBill', null);
        $this->assertSame(300.0, Meter::query()->find('m1')->currentReading);            // the reading staff took is still on record
        $this->assertSame('terminated', Lease::query()->find('lease_a')->status);
    }

    public function test_when_nothing_is_waiting_there_is_no_final_bill(): void
    {
        Meter::query()->where('id', 'm1')->update(['billedReading' => 250]);
        Meter::query()->where('id', 'm2')->update(['billedReading' => 20]);

        $this->terminate()->assertOk()->assertJsonPath('meta.finalBill', null);
    }
}

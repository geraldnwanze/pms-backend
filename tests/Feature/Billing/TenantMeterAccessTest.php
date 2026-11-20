<?php

namespace Tests\Feature\Billing;

use App\Models\Lease;
use App\Models\Meter;
use App\Models\Unit;
use Tests\PmsTestCase;

/** A tenant can read the meters of the unit they rent — to see their own use — and nothing else about metering. */
class TenantMeterAccessTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->meter('m_water', ['type' => 'water', 'unitOfMeasure' => 'm³', 'ratePerUnit' => 350, 'previousReading' => 200, 'currentReading' => 214]);
        $this->meter('m_elec');

        // Another unit in the same building (Alpha Court) and a shared meter that belongs to no unit.
        Unit::query()->create(['id' => 'unit_a2', 'buildingId' => $this->w->bA->id, 'propertyId' => 'prop_a', 'code' => 'A2', 'type' => '1-Bedroom Flat', 'floor' => 1, 'status' => 'occupied', 'rent' => 800_000]);
        $this->meter('m_neighbour', ['unitId' => 'unit_a2']);
        $this->meter('m_common', ['unitId' => null]);
    }

    private function meter(string $id, array $over = []): Meter
    {
        return Meter::query()->create($over + [
            'id' => $id, 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => 'electricity', 'meterNumber' => strtoupper($id),
            'unitOfMeasure' => 'kWh', 'previousReading' => 100, 'currentReading' => 250, 'ratePerUnit' => 60,
            'history' => [['date' => '2026-09-01', 'value' => 100], ['date' => '2026-10-01', 'value' => 250]],
        ]);
    }

    public function test_a_tenant_lists_only_the_meters_of_their_own_unit(): void
    {
        $ids = collect($this->as($this->w->tenantUser)->getJson('/api/v1/meters')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(['m_elec', 'm_water'], $ids);
    }

    public function test_the_reading_history_and_what_is_unbilled_come_with_it(): void
    {
        $water = collect($this->as($this->w->tenantUser)->getJson('/api/v1/meters')->json('data'))->firstWhere('id', 'm_water');

        $this->assertSame('water', $water['type']);
        $this->assertSame('m³', $water['unitOfMeasure']);
        $this->assertEquals(14, $water['unbilledUnits']);              // 200 → 214, never billed
        $this->assertEquals(4_900, $water['unbilledAmount']);          // 14 m³ × ₦350
        $this->assertCount(2, $water['history']);
    }

    public function test_a_tenant_cannot_open_a_neighbours_or_a_shared_meter_by_id(): void
    {
        $this->as($this->w->tenantUser)->getJson('/api/v1/meters/m_water')->assertOk();
        $this->as($this->w->tenantUser)->getJson('/api/v1/meters/m_neighbour')->assertForbidden();
        $this->as($this->w->tenantUser)->getJson('/api/v1/meters/m_common')->assertForbidden();
    }

    public function test_the_bootstrap_dataset_carries_the_tenants_meters_and_no_others(): void
    {
        $ids = collect($this->as($this->w->tenantUser)->getJson('/api/v1/bootstrap')->assertOk()->json('data.meters'))->pluck('id')->sort()->values()->all();

        $this->assertSame(['m_elec', 'm_water'], $ids);
    }

    public function test_a_tenant_still_cannot_change_log_readings_for_or_bill_a_meter(): void
    {
        $t = $this->as($this->w->tenantUser);

        $t->postJson('/api/v1/meters', ['propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => 'water', 'meterNumber' => 'X', 'unitOfMeasure' => 'm³', 'ratePerUnit' => 1, 'previousReading' => 0, 'currentReading' => 0])->assertForbidden();
        $t->patchJson('/api/v1/meters/m_water', ['ratePerUnit' => 1])->assertForbidden();
        $t->deleteJson('/api/v1/meters/m_water')->assertForbidden();
        $t->postJson('/api/v1/meters/m_water/readings', ['value' => 300, 'date' => '2026-11-18'])->assertForbidden();
        $t->postJson('/api/v1/meters/m_water/bill')->assertForbidden();

        $this->assertEquals(350, Meter::query()->find('m_water')->ratePerUnit);
        $this->assertEquals(214, Meter::query()->find('m_water')->currentReading);
    }

    public function test_someone_with_no_tenancy_here_sees_none_of_these_meters(): void
    {
        $this->as($this->w->stranger)->getJson('/api/v1/meters')->assertForbidden();
        $this->assertSame([], $this->as($this->w->stranger)->getJson('/api/v1/bootstrap')->json('data.meters'));
    }

    // ---- only the unit they hold today --------------------------------------------------------------------------

    private function metersSeenByTenant(): array
    {
        return collect($this->as($this->w->tenantUser)->getJson('/api/v1/meters')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_a_tenant_whose_lease_has_ended_no_longer_sees_that_units_meters(): void
    {
        foreach (['expired', 'terminated'] as $status) {
            Lease::query()->where('id', 'lease_a')->update(['status' => $status]);

            $this->assertSame([], $this->metersSeenByTenant(), $status);
            $this->as($this->w->tenantUser)->getJson('/api/v1/meters/m_elec')->assertForbidden();
        }
    }

    public function test_a_lease_that_ran_out_yesterday_or_starts_tomorrow_does_not_count_either(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['startDate' => now()->subYear()->toDateString(), 'endDate' => now()->subDay()->toDateString()]);
        $this->assertSame([], $this->metersSeenByTenant(), 'ended yesterday, though the status has not caught up');

        Lease::query()->where('id', 'lease_a')->update(['startDate' => now()->addDay()->toDateString(), 'endDate' => now()->addYear()->toDateString()]);
        $this->assertSame([], $this->metersSeenByTenant(), 'not yet started');
    }

    public function test_the_last_day_of_a_lease_still_counts(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['endDate' => now()->toDateString()]);

        $this->assertSame(['m_elec', 'm_water'], $this->metersSeenByTenant());
    }

    public function test_a_tenant_who_moved_sees_the_new_unit_and_not_the_old_one(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['status' => 'terminated']);
        Lease::query()->create([
            'id' => 'lease_a2', 'code' => 'LSE-A2', 'unitId' => 'unit_a2', 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada',
            'startDate' => now()->subMonth()->toDateString(), 'endDate' => now()->addMonths(11)->toDateString(),
            'rent' => 800_000, 'billingCycle' => 'annual', 'status' => 'active', 'signed' => true,
        ]);

        $this->assertSame(['m_neighbour'], $this->metersSeenByTenant());
        $this->as($this->w->tenantUser)->getJson('/api/v1/meters/m_neighbour')->assertOk();
        $this->as($this->w->tenantUser)->getJson('/api/v1/meters/m_elec')->assertForbidden();      // the old unit, now someone else's
    }

    public function test_staff_still_see_every_meter_whatever_the_state_of_the_lease(): void
    {
        Lease::query()->where('id', 'lease_a')->update(['status' => 'terminated']);

        $ids = collect($this->as($this->w->managerA)->getJson('/api/v1/meters')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing(['m_water', 'm_elec', 'm_neighbour', 'm_common'], $ids);
    }
}

<?php

namespace Tests\Feature\Billing;

use App\Models\Building;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;

/** A unit is let to one tenant at a time, and its status follows its leases. */
class LeaseUnitRulesTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->unit('u_free', 'available');
    }

    private function unit(string $id, string $status, string $building = 'bld_a', string $property = 'prop_a'): Unit
    {
        return Unit::query()->create(['id' => $id, 'buildingId' => $building, 'propertyId' => $property, 'code' => strtoupper($id), 'type' => 'Flat', 'status' => $status, 'rent' => 500_000]);
    }

    private function lease(string $unit, string $start, string $end, array $over = []): array
    {
        return $over + ['unitId' => $unit, 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada', 'startDate' => $start, 'endDate' => $end, 'rent' => 500_000, 'billingCycle' => 'annual'];
    }

    private function let(string $unit, string $start, string $end, string $status = 'active', ?string $id = null): Lease
    {
        return Lease::query()->create([
            'id' => $id ?? 'l_'.uniqid(), 'code' => 'L-'.strtoupper(substr(uniqid(), -5)), 'unitId' => $unit, 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada',
            'startDate' => $start, 'endDate' => $end, 'rent' => 1, 'billingCycle' => 'annual', 'status' => $status, 'signed' => true,
        ]);
    }

    private function unitStatus(string $unit): string
    {
        return Unit::query()->find($unit)->status;
    }

    // ---- Letting a unit takes it off the market ----------------------------------

    public function test_letting_an_available_or_reserved_unit_makes_it_occupied(): void
    {
        $this->unit('u_res', 'reserved');
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/leases', $this->lease('u_free', '2027-01-01', '2027-12-31'))->assertCreated();
        $this->postJson('/api/v1/leases', $this->lease('u_res', '2027-01-01', '2027-12-31'))->assertCreated();

        $this->assertSame('occupied', $this->unitStatus('u_free'));
        $this->assertSame('occupied', $this->unitStatus('u_res'));
    }

    public function test_a_unit_under_repair_keeps_that_status_when_it_is_let(): void
    {
        foreach (['maintenance', 'renovation', 'unavailable'] as $status) {
            $this->unit("u_{$status}", $status);
            $this->as($this->w->managerA)->postJson('/api/v1/leases', $this->lease("u_{$status}", '2027-01-01', '2027-12-31'))->assertCreated();

            $this->assertSame($status, $this->unitStatus("u_{$status}"));
        }
    }

    public function test_the_dashboard_counts_the_newly_let_unit_as_occupied(): void
    {
        $before = $this->as($this->w->ownerA)->getJson('/api/v1/dashboard')->json('data.kpis');

        $this->as($this->w->managerA)->postJson('/api/v1/leases', $this->lease('u_free', '2027-01-01', '2027-12-31'))->assertCreated();

        $after = $this->as($this->w->ownerA)->getJson('/api/v1/dashboard')->json('data.kpis');
        $this->assertSame($before['occupied'] + 1, $after['occupied']);
        $this->assertSame($before['vacant'] - 1, $after['vacant']);
        $this->assertSame($before['annualRentRoll'] + 500_000, $after['annualRentRoll']);
    }

    // ---- One tenant at a time ----------------------------------------------------

    public function test_overlapping_leases_on_one_unit_are_refused(): void
    {
        $first = $this->let('u_free', '2027-01-01', '2027-12-31');
        $this->as($this->w->managerA);

        $cases = [
            'identical' => ['2027-01-01', '2027-12-31'],
            'starts inside' => ['2027-06-01', '2028-05-31'],
            'ends inside' => ['2026-06-01', '2027-03-01'],
            'contains it' => ['2026-01-01', '2029-01-01'],
            'sits inside it' => ['2027-03-01', '2027-04-01'],
        ];
        foreach ($cases as $name => [$start, $end]) {
            $res = $this->postJson('/api/v1/leases', $this->lease('u_free', $start, $end))->assertStatus(422, $name);
            $this->assertStringContainsString($first->code, (string) $res->json('message'), "{$name}: the message names the lease in the way");
        }
        $this->assertSame(1, Lease::query()->where('unitId', 'u_free')->count());
    }

    public function test_a_lease_that_starts_the_day_the_last_one_ends_is_fine(): void
    {
        $this->let('u_free', '2027-01-01', '2027-12-31');
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/leases', $this->lease('u_free', '2027-12-31', '2028-12-30'))->assertCreated();
        $this->postJson('/api/v1/leases', $this->lease('u_free', '2026-01-01', '2027-01-01'))->assertCreated();       // and one that ends the day it starts
    }

    public function test_leases_that_are_over_do_not_block_the_unit(): void
    {
        $this->let('u_free', '2027-01-01', '2027-12-31', 'terminated');
        $this->let('u_free', '2027-01-01', '2027-12-31', 'expired');
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/leases', $this->lease('u_free', '2027-03-01', '2028-02-28'))->assertCreated();
    }

    public function test_renewed_and_expiring_leases_still_hold_the_unit(): void
    {
        $this->let('u_free', '2027-01-01', '2027-12-31', 'expiring');
        $this->unit('u_two', 'occupied');
        $this->let('u_two', '2027-01-01', '2027-12-31', 'renewed');
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/leases', $this->lease('u_free', '2027-06-01', '2028-05-31'))->assertStatus(422);
        $this->postJson('/api/v1/leases', $this->lease('u_two', '2027-06-01', '2028-05-31'))->assertStatus(422);
    }

    public function test_a_unit_that_is_already_let_cannot_be_let_again_for_the_same_period(): void
    {
        // unit_a carries the world's lease_a (six months back to six months ahead of "now")
        $this->as($this->w->managerA)->postJson('/api/v1/leases', $this->lease('unit_a', '2026-12-01', '2027-03-01'))->assertStatus(422);
    }

    public function test_different_units_are_independent(): void
    {
        $this->unit('u_other', 'available');
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/leases', $this->lease('u_free', '2027-01-01', '2027-12-31'))->assertCreated();
        $this->postJson('/api/v1/leases', $this->lease('u_other', '2027-01-01', '2027-12-31'))->assertCreated();       // same tenant, same dates, other unit
    }

    // ---- Changing a lease ---------------------------------------------------------

    public function test_a_renewal_cannot_run_into_the_next_lease(): void
    {
        $first = $this->let('u_free', '2027-01-01', '2027-06-30');
        $this->let('u_free', '2027-09-01', '2028-08-31');
        $this->as($this->w->managerA);

        $this->postJson("/api/v1/leases/{$first->id}/renew", ['endDate' => '2027-10-01'])->assertStatus(422);
        $this->assertSame('2027-06-30', $first->fresh()->endDate);

        $this->postJson("/api/v1/leases/{$first->id}/renew", ['endDate' => '2027-08-15'])->assertOk()->assertJsonPath('data.status', 'renewed');
    }

    public function test_renewing_a_lease_does_not_clash_with_itself(): void
    {
        $first = $this->let('u_free', '2027-01-01', '2027-06-30');

        $this->as($this->w->managerA)->postJson("/api/v1/leases/{$first->id}/renew", ['endDate' => '2028-06-30'])->assertOk();
    }

    public function test_editing_the_dates_cannot_create_an_overlap(): void
    {
        $first = $this->let('u_free', '2027-01-01', '2027-06-30');
        $this->let('u_free', '2027-09-01', '2028-08-31');
        $this->as($this->w->managerA);

        $this->patchJson("/api/v1/leases/{$first->id}", ['endDate' => '2027-10-01'])->assertStatus(422);
        $this->patchJson("/api/v1/leases/{$first->id}", ['startDate' => '2026-01-01', 'endDate' => '2027-08-31'])->assertOk();
        $this->patchJson("/api/v1/leases/{$first->id}", ['endDate' => '2027-03-01'])->assertOk();
    }

    public function test_the_dates_of_a_finished_lease_can_still_be_corrected(): void
    {
        $this->let('u_free', '2027-01-01', '2027-12-31');
        $old = $this->let('u_free', '2027-01-01', '2027-12-31', 'terminated');

        $this->as($this->w->managerA)->patchJson("/api/v1/leases/{$old->id}", ['endDate' => '2027-11-30'])->assertOk();
    }

    public function test_a_lease_cannot_be_moved_to_another_unit(): void
    {
        $first = $this->let('u_free', '2027-01-01', '2027-06-30');
        $this->as($this->w->managerA);

        $this->patchJson("/api/v1/leases/{$first->id}", ['unitId' => 'unit_a'])->assertStatus(422);
        $this->assertSame('u_free', $first->fresh()->unitId);
        $this->patchJson("/api/v1/leases/{$first->id}", ['unitId' => 'u_free', 'rent' => 600_000])->assertOk();   // restating it is fine
    }

    // ---- Ending a lease -------------------------------------------------------------

    public function test_terminating_the_only_lease_frees_the_unit(): void
    {
        $this->unit('u_one', 'occupied');
        $lease = $this->let('u_one', '2026-01-01', '2027-12-31');

        $this->as($this->w->managerA)->postJson("/api/v1/leases/{$lease->id}/terminate")->assertOk();

        $this->assertSame('available', $this->unitStatus('u_one'));
    }

    public function test_terminating_a_lease_does_not_free_a_unit_that_is_let_again_next(): void
    {
        $this->unit('u_one', 'occupied');
        $now = $this->let('u_one', '2026-01-01', '2026-12-31');
        $this->let('u_one', '2027-01-01', '2027-12-31');           // already signed for next year

        $this->as($this->w->managerA)->postJson("/api/v1/leases/{$now->id}/terminate")->assertOk();

        $this->assertSame('occupied', $this->unitStatus('u_one'));
    }

    // ---- The unit's own status ---------------------------------------------------------

    public function test_a_unit_with_a_current_lease_cannot_be_marked_available_by_hand(): void
    {
        $this->as($this->w->managerA)->patchJson('/api/v1/units/unit_a', ['status' => 'available'])->assertStatus(422);

        $this->assertSame('occupied', $this->unitStatus('unit_a'));
    }

    public function test_other_status_changes_are_still_allowed_on_a_leased_unit(): void
    {
        $this->as($this->w->managerA)->patchJson('/api/v1/units/unit_a', ['status' => 'maintenance'])->assertOk();
        $this->patchJson('/api/v1/units/unit_a', ['status' => 'occupied'])->assertOk();
    }

    public function test_a_unit_can_be_marked_available_once_the_lease_is_over(): void
    {
        $this->unit('u_one', 'occupied');
        $this->let('u_one', '2026-01-01', '2027-12-31', 'terminated');

        $this->as($this->w->managerA)->patchJson('/api/v1/units/u_one', ['status' => 'available'])->assertOk();
    }

    public function test_restating_available_on_a_vacant_unit_is_fine(): void
    {
        $this->as($this->w->managerA)->patchJson('/api/v1/units/u_free', ['status' => 'available', 'rent' => 700_000])->assertOk();
    }

    // ---- Deleting ---------------------------------------------------------------------

    public function test_a_lease_with_invoices_or_payments_cannot_be_deleted(): void
    {
        $this->as($this->w->admin)->deleteJson('/api/v1/leases/lease_a')->assertStatus(422);
        $this->assertNotNull(Lease::query()->find('lease_a'));

        $lease = $this->let('u_free', '2027-01-01', '2027-12-31');
        Payment::query()->create(['id' => 'pay_x', 'code' => 'PX', 'invoiceId' => $this->w->invoice->id, 'leaseId' => $lease->id, 'unitId' => 'u_free', 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada', 'type' => 'rent', 'method' => 'cash', 'amount' => 1, 'paymentDate' => '2026-11-01', 'reference' => 'r', 'status' => 'approved']);
        $this->deleteJson("/api/v1/leases/{$lease->id}")->assertStatus(422);
    }

    public function test_a_lease_made_by_mistake_can_be_deleted_and_the_unit_is_freed(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/leases', $this->lease('u_free', '2027-01-01', '2027-12-31'))->assertCreated();
        $id = Lease::query()->where('unitId', 'u_free')->value('id');
        $this->assertSame('occupied', $this->unitStatus('u_free'));

        $this->as($this->w->admin)->deleteJson("/api/v1/leases/{$id}")->assertOk();

        $this->assertSame('available', $this->unitStatus('u_free'));
    }

    public function test_a_unit_with_leases_on_record_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $this->unit('u_used', 'available');
        $this->let('u_used', '2026-01-01', '2026-06-30', 'expired');
        $this->as($this->w->admin);

        $this->deleteJson('/api/v1/units/u_used')->assertStatus(422);
        $this->deleteJson('/api/v1/units/u_free')->assertOk();
    }

    public function test_a_building_with_units_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $empty = Building::query()->create(['id' => 'bld_empty', 'propertyId' => 'prop_a', 'name' => 'Empty', 'code' => 'B-E', 'floors' => 1]);
        $this->as($this->w->admin);

        $this->deleteJson('/api/v1/buildings/bld_a')->assertStatus(422);
        $this->assertNotNull(Unit::query()->find('u_free'));
        $this->deleteJson("/api/v1/buildings/{$empty->id}")->assertOk();
    }

    public function test_a_property_with_buildings_units_or_leases_cannot_be_deleted_but_an_empty_one_can(): void
    {
        Property::query()->create(['id' => 'prop_empty', 'code' => 'PE', 'name' => 'Empty Estate', 'type' => 'residential', 'status' => 'active', 'owners' => [['ownerId' => $this->w->ownerA->id, 'percentage' => 100]], 'assignedStaffIds' => [], 'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria', 'createdAt' => '2026-01-01']);
        $this->as($this->w->admin);

        $this->deleteJson('/api/v1/properties/prop_a')->assertStatus(422);
        $this->deleteJson('/api/v1/properties/prop_empty')->assertOk();
    }

    public function test_each_thing_a_property_holds_is_enough_on_its_own_to_stop_it_being_deleted(): void
    {
        $make = fn (string $id) => Property::query()->create(['id' => $id, 'code' => strtoupper($id), 'name' => $id, 'type' => 'residential', 'status' => 'active', 'owners' => [['ownerId' => $this->w->ownerA->id, 'percentage' => 100]], 'assignedStaffIds' => [], 'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria', 'createdAt' => '2026-01-01']);

        $make('p_with_building');
        Building::query()->create(['id' => 'b_only', 'propertyId' => 'p_with_building', 'name' => 'B', 'code' => 'B', 'floors' => 1]);
        $make('p_with_unit');
        Unit::query()->create(['id' => 'u_only', 'buildingId' => 'bld_a', 'propertyId' => 'p_with_unit', 'code' => 'U', 'type' => 'Flat', 'status' => 'available', 'rent' => 1]);
        $make('p_with_lease');
        Lease::query()->create(['id' => 'l_only', 'code' => 'LO', 'unitId' => 'unit_a', 'propertyId' => 'p_with_lease', 'tenantId' => 'ten_ada', 'startDate' => '2020-01-01', 'endDate' => '2020-12-31', 'rent' => 1, 'billingCycle' => 'annual', 'status' => 'expired', 'signed' => true]);

        $this->as($this->w->admin);
        foreach (['p_with_building', 'p_with_unit', 'p_with_lease'] as $id) {
            $this->deleteJson("/api/v1/properties/{$id}")->assertStatus(422);
            $this->assertNotNull(Property::query()->find($id), $id);
        }
    }

    public function test_a_tenant_with_leases_on_record_cannot_be_deleted(): void
    {
        $this->as($this->w->admin)->deleteJson('/api/v1/tenants/ten_ada')->assertStatus(422);
        $this->assertNotNull(Tenant::query()->find('ten_ada'));
    }
}

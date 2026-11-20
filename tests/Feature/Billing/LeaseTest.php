<?php

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\Lease;
use App\Models\Notification;
use App\Models\Unit;
use Tests\PmsTestCase;

/** Leases: creation, renewing, terminating (which frees the unit) and who may do what. */
class LeaseTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->w->uA->forceFill(['status' => 'occupied'])->save();

        // a vacant unit to let (unit_a already has lease_a)
        Unit::query()->create(['id' => 'unit_free', 'buildingId' => 'bld_a', 'propertyId' => 'prop_a', 'code' => 'FREE', 'type' => 'Flat', 'status' => 'available', 'rent' => 1]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'unitId' => 'unit_free', 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada',
            'startDate' => '2027-01-01', 'endDate' => '2027-12-31', 'rent' => 1_200_000, 'billingCycle' => 'annual',
        ], $over);
    }

    // ---- Creating -----------------------------------------------------------

    public function test_a_manager_creates_a_lease_with_sensible_defaults(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/leases', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.noticePeriodDays', 90)
            ->assertJsonPath('data.signed', false)
            ->json('data.id');

        $this->assertNotEmpty(Lease::query()->findOrFail($id)->code);
    }

    public function test_the_tenant_is_told_and_the_creation_is_audited(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/leases', $this->payload())->json('data.id');

        $this->assertTrue(Notification::query()->where('forUserId', 'ten_ada')->where('title', 'New lease created')->exists());
        $this->assertTrue(AuditLog::query()->where('entityId', $id)->where('action', 'create')->exists());
    }

    public function test_the_end_date_must_come_after_the_start_and_fields_must_be_valid(): void
    {
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/leases', $this->payload(['endDate' => '2027-01-01']))->assertStatus(422);
        $this->postJson('/api/v1/leases', $this->payload(['endDate' => '2026-06-01']))->assertStatus(422);
        $this->postJson('/api/v1/leases', $this->payload(['rent' => -1]))->assertStatus(422);
        $this->postJson('/api/v1/leases', $this->payload(['billingCycle' => 'weekly']))->assertStatus(422);
        $this->postJson('/api/v1/leases', $this->payload(['unitId' => 'nope']))->assertStatus(422);
        $this->postJson('/api/v1/leases', $this->payload(['status' => 'bogus']))->assertStatus(422);
    }

    public function test_a_lease_cannot_be_written_on_a_property_the_actor_is_not_part_of(): void
    {
        $before = Lease::query()->count();

        $this->as($this->w->managerA)->postJson('/api/v1/leases', $this->payload(['propertyId' => 'prop_b', 'unitId' => 'unit_b']))->assertForbidden();
        $this->as($this->w->ownerB)->postJson('/api/v1/leases', $this->payload())->assertForbidden();

        $this->assertSame($before, Lease::query()->count());
    }

    public function test_a_lease_cannot_claim_a_unit_from_a_different_property(): void
    {
        $this->as($this->w->managerA)
            ->postJson('/api/v1/leases', $this->payload(['unitId' => 'unit_b']))   // my property, their unit
            ->assertStatus(422);

        $this->assertSame(0, Lease::query()->where('unitId', 'unit_b')->where('rent', 1_200_000)->count());
    }

    public function test_roles_without_leasing_rights_cannot_create_leases(): void
    {
        foreach (['tenantUser', 'financeA', 'stranger'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/leases', $this->payload())->assertForbidden();
        }
    }

    // ---- Renewing -----------------------------------------------------------

    public function test_renewing_moves_the_end_date_marks_it_renewed_and_tells_the_tenant(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/leases/lease_a/renew', ['endDate' => '2028-06-30'])
            ->assertOk()
            ->assertJsonPath('data.status', 'renewed')
            ->assertJsonPath('data.endDate', '2028-06-30');

        $this->assertTrue(Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Lease renewed')->exists());
        $this->assertTrue(AuditLog::query()->where('entityId', 'lease_a')->where('action', 'edit')->exists());
    }

    public function test_renewing_needs_a_valid_end_date_later_than_the_current_one(): void
    {
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/leases/lease_a/renew', [])->assertStatus(422);
        $this->postJson('/api/v1/leases/lease_a/renew', ['endDate' => 'soon'])->assertStatus(422);
        $this->postJson('/api/v1/leases/lease_a/renew', ['endDate' => '2000-01-01'])->assertStatus(422);

        $this->assertSame('active', $this->w->lease->fresh()->status);
    }

    // ---- Terminating --------------------------------------------------------

    public function test_terminating_ends_the_lease_and_puts_the_unit_back_on_the_market(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/leases/lease_a/terminate')
            ->assertOk()->assertJsonPath('data.status', 'terminated');

        $this->assertSame('available', $this->w->uA->fresh()->status);
        $this->assertTrue(Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Lease terminated')->exists());
        $this->assertTrue(Notification::query()->where('forRole', 'agent')->where('title', 'Unit now available')->exists());
        $this->assertTrue(AuditLog::query()->where('entityId', 'lease_a')->where('action', 'edit')->exists());
    }

    public function test_terminating_one_lease_leaves_other_units_alone(): void
    {
        $this->w->uB->forceFill(['status' => 'occupied'])->save();

        $this->as($this->w->managerA)->postJson('/api/v1/leases/lease_a/terminate')->assertOk();

        $this->assertSame('occupied', $this->w->uB->fresh()->status);
    }

    // ---- Permissions on the actions ----------------------------------------

    public function test_only_staff_of_that_property_can_renew_or_terminate(): void
    {
        foreach (['ownerB', 'tenantUser', 'stranger', 'financeA'] as $who) {
            $this->as($this->w->{$who});
            $this->postJson('/api/v1/leases/lease_a/renew', ['endDate' => '2030-01-01'])->assertForbidden();
            $this->postJson('/api/v1/leases/lease_a/terminate')->assertForbidden();
        }

        $lease = $this->w->lease->fresh();
        $this->assertSame('active', $lease->status);
        $this->assertSame('occupied', $this->w->uA->fresh()->status);
    }

    public function test_unknown_leases_are_404_and_everything_needs_a_login(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/leases/nope/terminate')->assertNotFound();
        $this->app['auth']->forgetGuards();
    }

    public function test_the_actions_need_a_login(): void
    {
        $this->postJson('/api/v1/leases/lease_a/terminate')->assertUnauthorized();
        $this->postJson('/api/v1/leases/lease_a/renew', ['endDate' => '2030-01-01'])->assertUnauthorized();
        $this->postJson('/api/v1/leases', $this->payload())->assertUnauthorized();
    }

    // ---- Reading ------------------------------------------------------------

    public function test_tenants_see_only_their_own_lease_and_other_portfolios_stay_hidden(): void
    {
        $ids = fn () => collect($this->getJson('/api/v1/leases?per_page=100')->assertOk()->json('data'))->pluck('id')->all();

        $this->as($this->w->tenantUser);
        $this->assertSame(['lease_a'], $ids());

        $this->as($this->w->managerA);
        $this->assertSame(['lease_a'], $ids());

        $this->as($this->w->ownerB);
        $this->assertSame(['lease_b'], $ids());
    }
}

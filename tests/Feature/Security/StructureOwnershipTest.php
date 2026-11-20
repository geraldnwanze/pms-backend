<?php

namespace Tests\Feature\Security;

use App\Models\Building;
use App\Models\Property;
use App\Models\Unit;
use Tests\PmsTestCase;

/**
 * Owners may build out *their own* properties (properties, buildings, units) and nothing else.
 * Row-level scope and anti-reparenting are what stop an owner reaching into someone else's portfolio.
 */
class StructureOwnershipTest extends PmsTestCase
{
    // ---- Creating -----------------------------------------------------------

    public function test_an_owner_can_add_a_building_and_unit_to_their_own_property(): void
    {
        $this->as($this->w->ownerA);

        $building = $this->postJson('/api/v1/buildings', ['propertyId' => 'prop_a', 'name' => 'Block C', 'code' => 'BLD-C', 'floors' => 2])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/units', ['propertyId' => 'prop_a', 'buildingId' => $building, 'code' => 'C01', 'type' => 'Studio', 'status' => 'available', 'rent' => 500_000])
            ->assertCreated();
    }

    public function test_an_owner_cannot_add_a_building_to_someone_elses_property(): void
    {
        $this->as($this->w->ownerA)
            ->postJson('/api/v1/buildings', ['propertyId' => 'prop_b', 'name' => 'Sneaky block', 'code' => 'BLD-S'])
            ->assertForbidden();

        $this->assertSame(0, Building::query()->where('name', 'Sneaky block')->count());
    }

    public function test_an_owner_cannot_add_a_unit_to_someone_elses_property_or_building(): void
    {
        $this->as($this->w->ownerA);

        $this->postJson('/api/v1/units', ['propertyId' => 'prop_b', 'buildingId' => 'bld_b', 'code' => 'X1', 'type' => 'Flat', 'status' => 'available'])->assertForbidden();
        // claiming my property but their building
        $this->postJson('/api/v1/units', ['propertyId' => 'prop_a', 'buildingId' => 'bld_b', 'code' => 'X2', 'type' => 'Flat', 'status' => 'available'])->assertStatus(422);
        // naming only their building: the API requires the property, so the request never gets as far as deriving it
        $this->postJson('/api/v1/units', ['buildingId' => 'bld_b', 'code' => 'X3', 'type' => 'Flat', 'status' => 'available'])->assertStatus(422);

        $this->assertSame(0, Unit::query()->whereIn('code', ['X1', 'X2', 'X3'])->count());
    }

    public function test_roles_without_structural_rights_cannot_create_buildings(): void
    {
        foreach (['tenantUser', 'financeA', 'stranger'] as $who) {
            $this->as($this->w->{$who})
                ->postJson('/api/v1/buildings', ['propertyId' => 'prop_a', 'name' => "By {$who}", 'code' => 'BLD-'.strtoupper($who)])
                ->assertForbidden();
        }
    }

    public function test_a_manager_can_build_out_a_property_they_belong_to_but_not_others(): void
    {
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/buildings', ['propertyId' => 'prop_a', 'name' => 'Mgr block', 'code' => 'BLD-M1'])->assertCreated();
        $this->postJson('/api/v1/buildings', ['propertyId' => 'prop_b', 'name' => 'Mgr sneaky', 'code' => 'BLD-M2'])->assertForbidden();
    }

    public function test_a_registered_user_who_creates_a_property_becomes_its_owner(): void
    {
        $this->as($this->w->stranger);

        $id = $this->postJson('/api/v1/properties', ['name' => 'My First Property', 'type' => 'residential', 'address' => '1 Road', 'city' => 'Abuja', 'state' => 'Abuja Federal Capital Territory', 'country' => 'Nigeria'])
            ->assertCreated()->json('data.id');

        $property = Property::query()->findOrFail($id);
        $this->assertSame($this->w->stranger->id, $property->owners[0]['ownerId']);
        $this->assertSame('owner', $this->w->stranger->fresh()->role);
        $this->assertSame('owner', $this->w->stranger->roleOnProperty($id));
    }

    public function test_a_creator_cannot_make_someone_else_the_owner_or_manager_at_creation(): void
    {
        $this->as($this->w->stranger);

        $id = $this->postJson('/api/v1/properties', [
            'name' => 'Rigged', 'type' => 'residential', 'address' => '1 Road', 'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria',
            'owners' => [['ownerId' => $this->w->ownerB->id, 'percentage' => 100]],
            'managerId' => $this->w->managerA->id, 'assignedStaffIds' => [$this->w->financeA->id],
        ])->assertCreated()->json('data.id');

        $property = Property::query()->findOrFail($id);
        $this->assertSame($this->w->stranger->id, $property->owners[0]['ownerId']);
        $this->assertNull($property->managerId);
        $this->assertSame([], $property->assignedStaffIds);
    }

    // ---- Editing ------------------------------------------------------------

    public function test_an_owner_can_edit_their_own_building_unit_and_property(): void
    {
        $this->as($this->w->ownerA);

        $this->putJson('/api/v1/buildings/bld_a', ['name' => 'Renamed'])->assertOk();
        $this->putJson('/api/v1/units/unit_a', ['type' => '3-Bedroom Flat'])->assertOk();
        $this->putJson('/api/v1/properties/prop_a', ['name' => 'Alpha Court II'])->assertOk();

        $this->assertSame('Renamed', Building::query()->find('bld_a')->name);
    }

    public function test_an_owner_cannot_edit_someone_elses_building_unit_or_property(): void
    {
        $this->as($this->w->ownerA);

        foreach ([
            '/api/v1/buildings/bld_b' => ['name' => 'Hijacked'],
            '/api/v1/units/unit_b' => ['type' => 'Hijacked'],
            '/api/v1/properties/prop_b' => ['name' => 'Hijacked'],
        ] as $url => $payload) {
            $status = $this->putJson($url, $payload)->status();
            $this->assertContains($status, [403, 404], "PUT {$url} returned {$status}");
        }

        $this->assertSame('Block B', Building::query()->find('bld_b')->name);
        $this->assertSame('Beta Heights', Property::query()->find('prop_b')->name);
    }

    public function test_a_building_cannot_be_moved_into_another_portfolio(): void
    {
        $this->as($this->w->ownerA)
            ->putJson('/api/v1/buildings/bld_a', ['propertyId' => 'prop_b'])
            ->assertStatus(422);

        $this->assertSame('prop_a', Building::query()->find('bld_a')->propertyId);
    }

    public function test_a_unit_cannot_be_moved_to_another_property_or_into_a_foreign_building(): void
    {
        $this->as($this->w->ownerA);

        $this->putJson('/api/v1/units/unit_a', ['propertyId' => 'prop_b'])->assertStatus(422);
        $this->putJson('/api/v1/units/unit_a', ['buildingId' => 'bld_b'])->assertStatus(422);

        $unit = Unit::query()->find('unit_a');
        $this->assertSame('prop_a', $unit->propertyId);
        $this->assertSame('bld_a', $unit->buildingId);
    }

    public function test_restating_the_current_property_on_update_is_harmless(): void
    {
        $this->as($this->w->ownerA)->putJson('/api/v1/buildings/bld_a', ['propertyId' => 'prop_a', 'name' => 'Same home'])->assertOk();
    }

    public function test_an_owner_cannot_rewrite_ownership_or_team_columns(): void
    {
        $this->as($this->w->ownerA);

        foreach ([
            ['owners' => [['ownerId' => $this->w->stranger->id, 'percentage' => 100]]],
            ['managerId' => $this->w->stranger->id],
            ['assignedStaffIds' => [$this->w->stranger->id]],
        ] as $payload) {
            $this->putJson('/api/v1/properties/prop_a', $payload)->assertForbidden();
        }

        $property = Property::query()->find('prop_a');
        $this->assertSame($this->w->ownerA->id, $property->owners[0]['ownerId']);
        $this->assertNull($property->managerId);
    }

    public function test_an_admin_can_still_reassign_ownership(): void
    {
        $this->as($this->w->admin)
            ->putJson('/api/v1/properties/prop_a', ['managerId' => $this->w->managerA->id])
            ->assertOk();

        $this->assertSame($this->w->managerA->id, Property::query()->find('prop_a')->managerId);
    }

    public function test_an_owner_cannot_delete_records_at_all(): void
    {
        $this->as($this->w->ownerA);

        $this->deleteJson('/api/v1/buildings/bld_a')->assertForbidden();
        $this->deleteJson('/api/v1/units/unit_a')->assertForbidden();
        $this->deleteJson('/api/v1/properties/prop_a')->assertForbidden();
        $this->assertNotNull(Building::query()->find('bld_a'));
    }
}

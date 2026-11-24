<?php

namespace Tests\Feature\Modules;

use App\Models\AuditLog;
use App\Models\Meter;
use App\Models\Setting;
use App\Models\User;
use App\Services\PropertyScope;
use App\Support\EntityRegistry;
use App\Support\Modules;
use Tests\PmsTestCase;
use Tests\Support\World;

/** A superadmin switches modules on and off; a module that is off is refused, hidden and not shipped, and keeps its data. */
class ModulesTest extends PmsTestCase
{
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = World::user('superadmin', 'super@pms.test');
        Meter::query()->create([
            'id' => 'm1', 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => 'electricity', 'meterNumber' => 'M1',
            'unitOfMeasure' => 'kWh', 'previousReading' => 100, 'currentReading' => 250, 'ratePerUnit' => 60, 'history' => [],
        ]);
    }

    private function toggle(string $key, bool $enabled, array $extra = [], ?User $as = null)
    {
        return $this->as($as ?? $this->superadmin)->putJson("/api/v1/modules/{$key}", ['enabled' => $enabled] + $extra);
    }

    private function states(): array
    {
        return collect($this->as($this->superadmin)->getJson('/api/v1/modules')->assertOk()->json('data'))->pluck('enabled', 'key')->all();
    }

    // ---- the registry itself --------------------------------------------------------------------------------

    public function test_every_module_names_real_resources_and_real_requirements_with_no_cycles(): void
    {
        $resources = EntityRegistry::keys();
        $owner = [];
        foreach (Modules::DEFINITIONS as $key => $def) {
            foreach ($def['resources'] as $r) {
                $this->assertContains($r, $resources, "{$key}: {$r} is not a REST resource");
                $this->assertArrayNotHasKey($r, $owner, "{$r} belongs to two modules");
                $owner[$r] = $key;
            }
            foreach ($def['requires'] as $needed) {
                $this->assertArrayHasKey($needed, Modules::DEFINITIONS, "{$key} needs an unknown module");
                $this->assertNotContains($key, (new Modules)->dependentsOf($key), "{$key} depends on itself");
            }
            $this->assertNotSame('', $def['label']);
            $this->assertNotSame('', $def['description']);
        }
    }

    public function test_the_core_resources_are_not_switchable(): void
    {
        foreach (['properties', 'buildings', 'units', 'tenants', 'leases', 'invoices', 'payments', 'notifications', 'users', 'audit'] as $core) {
            $this->assertNull(Modules::forResource($core), $core);
        }
    }

    // ---- who may read and switch ----------------------------------------------------------------------------

    public function test_every_module_is_on_by_default_and_anyone_signed_in_can_read_the_list(): void
    {
        foreach (['superadmin', 'admin', 'managerA', 'financeA', 'tenantUser', 'stranger'] as $who) {
            $user = $who === 'superadmin' ? $this->superadmin : $this->w->{$who};
            $rows = $this->as($user)->getJson('/api/v1/modules')->assertOk()->json('data');
            $this->assertSame(Modules::keys(), array_column($rows, 'key'));
            $this->assertNotContains(false, array_column($rows, 'enabled'), $who);
        }
    }

    public function test_the_list_and_the_switch_need_a_login(): void
    {
        $this->getJson('/api/v1/modules')->assertUnauthorized();
        $this->putJson('/api/v1/modules/utilities', ['enabled' => false])->assertUnauthorized();
    }

    public function test_only_a_superadmin_can_switch_a_module(): void
    {
        foreach (['admin', 'ownerA', 'managerA', 'financeA', 'tenantUser', 'stranger'] as $who) {
            $this->toggle('utilities', false, [], $this->w->{$who})->assertForbidden();
        }
        $this->assertNull(Setting::query()->find('modules'));
        $this->assertTrue($this->states()['utilities']);
    }

    public function test_the_request_is_validated_and_an_unknown_module_is_refused(): void
    {
        $this->as($this->superadmin)->putJson('/api/v1/modules/utilities', [])->assertUnprocessable();
        $this->as($this->superadmin)->putJson('/api/v1/modules/utilities', ['enabled' => 'maybe'])->assertUnprocessable();
        $this->toggle('nope', false)->assertNotFound();
    }

    // ---- what off does ----------------------------------------------------------------------------------------

    public function test_a_module_that_is_off_refuses_its_routes_for_everyone_including_the_superadmin(): void
    {
        $this->as($this->w->managerA)->getJson('/api/v1/meters')->assertOk();

        $this->toggle('utilities', false)->assertOk()->assertJsonPath('meta.changed', ['utilities']);

        foreach ([$this->superadmin, $this->w->admin, $this->w->managerA, $this->w->tenantUser] as $user) {
            $this->as($user)->getJson('/api/v1/meters')
                ->assertForbidden()->assertJsonPath('message', 'The Utilities & meters module is turned off.')->assertJsonPath('module', 'utilities');
        }
        $this->as($this->w->managerA)->postJson('/api/v1/meters', ['id' => 'x'])->assertForbidden();
        $this->as($this->w->managerA)->deleteJson('/api/v1/meters/m1')->assertForbidden();
    }

    public function test_switching_one_module_off_leaves_the_rest_and_the_core_working(): void
    {
        $this->toggle('utilities', false)->assertOk();

        $this->as($this->w->managerA)->getJson('/api/v1/invoices')->assertOk();
        $this->as($this->w->managerA)->getJson('/api/v1/leases')->assertOk();
        $this->as($this->w->managerA)->getJson('/api/v1/maintenance')->assertOk();
        $this->assertSame(['utilities' => false], array_filter($this->states(), fn ($on) => ! $on));
    }

    public function test_every_resource_of_a_module_is_refused_while_it_is_off_and_works_again_after(): void
    {
        foreach (Modules::DEFINITIONS as $key => $def) {
            foreach ($def['resources'] as $resource) {
                $this->toggle($key, false, ['cascade' => true])->assertOk();
                $this->as($this->superadmin)->getJson("/api/v1/{$resource}")->assertForbidden()->assertJsonPath('module', $key);

                $this->toggle($key, true)->assertOk();
                $this->as($this->superadmin)->getJson("/api/v1/{$resource}")->assertOk();
            }
        }
    }

    public function test_the_data_is_kept_and_comes_back_when_the_module_is_switched_on_again(): void
    {
        $this->toggle('utilities', false)->assertOk();
        $this->assertNotNull(Meter::query()->find('m1'));

        $this->toggle('utilities', true)->assertOk();
        $this->as($this->w->managerA)->getJson('/api/v1/meters/m1')->assertOk()->assertJsonPath('data.currentReading', 250);
    }

    public function test_the_bootstrap_carries_the_states_and_ships_nothing_for_a_module_that_is_off(): void
    {
        $on = $this->as($this->w->managerA)->getJson('/api/v1/bootstrap')->assertOk();
        $this->assertTrue($on->json('data.modules.utilities'));
        $this->assertCount(1, $on->json('data.meters'));

        $this->toggle('utilities', false)->assertOk();

        $off = $this->as($this->w->managerA)->getJson('/api/v1/bootstrap')->assertOk();
        $this->assertFalse($off->json('data.modules.utilities'));
        $this->assertTrue($off->json('data.modules.maintenance'));
        $this->assertSame([], $off->json('data.meters'));
        $this->assertNotEmpty($off->json('data.invoices'), 'the core is still shipped');
    }

    public function test_the_state_is_stored_so_it_survives_across_requests(): void
    {
        $this->toggle('planner', false)->assertOk();

        $this->assertSame(['planner'], Setting::query()->find('modules')->value['disabled']);
        $this->assertFalse((new Modules)->enabled('planner'));
        $this->assertTrue((new Modules)->enabled('utilities'));
    }

    // ---- dependencies ---------------------------------------------------------------------------------------

    public function test_switching_off_a_module_that_others_need_says_so_and_changes_nothing_until_confirmed(): void
    {
        $this->toggle('maintenance', false)->assertUnprocessable()
            ->assertJsonPath('errors.dependents.0', 'Technician payouts needs Maintenance and would be turned off too.');
        $this->assertTrue($this->states()['maintenance']);
        $this->assertTrue($this->states()['payouts']);

        $this->toggle('maintenance', false, ['cascade' => true])->assertOk()->assertJsonPath('meta.changed', ['maintenance', 'payouts']);
        $states = $this->states();
        $this->assertFalse($states['maintenance']);
        $this->assertFalse($states['payouts']);
    }

    public function test_a_module_is_off_while_the_one_it_needs_is_off_and_cannot_be_switched_on_first(): void
    {
        $this->toggle('maintenance', false, ['cascade' => true])->assertOk();

        $this->toggle('payouts', true)->assertUnprocessable()->assertJsonPath('errors.requires.0', 'Maintenance must be on first.');

        $this->toggle('maintenance', true)->assertOk();
        $this->assertFalse($this->states()['payouts'], 'switching the parent on again does not silently switch the child on');
        $this->toggle('payouts', true)->assertOk();
        $this->assertTrue($this->states()['payouts']);
    }

    public function test_a_module_whose_requirement_is_off_reads_as_off_even_if_it_was_never_switched(): void
    {
        Setting::query()->create(['key' => 'modules', 'value' => ['disabled' => ['maintenance']]]);

        $modules = new Modules;
        $this->assertFalse($modules->enabled('maintenance'));
        $this->assertFalse($modules->enabled('payouts'));
        $this->assertTrue($modules->enabled('utilities'));
    }

    public function test_switching_to_the_state_a_module_is_already_in_changes_and_records_nothing(): void
    {
        $this->toggle('utilities', true)->assertOk()->assertJsonPath('message', 'No change.')->assertJsonPath('meta.changed', []);
        $this->toggle('utilities', false)->assertOk();
        $before = AuditLog::query()->where('entity', 'Module')->count();

        $this->toggle('utilities', false)->assertOk()->assertJsonPath('meta.changed', []);

        $this->assertSame($before, AuditLog::query()->where('entity', 'Module')->count());
    }

    public function test_each_change_is_recorded_in_the_audit_trail_with_who_made_it(): void
    {
        $this->toggle('maintenance', false, ['cascade' => true])->assertOk();
        $this->toggle('maintenance', true)->assertOk();

        $summaries = AuditLog::query()->where('entity', 'Module')->orderBy('timestamp')->pluck('summary', 'entityId')->all();
        $this->assertSame('Turned on the Maintenance module', $summaries['maintenance'] ?? null);   // the later write wins per key
        $this->assertSame('Turned off the Technician payouts module', $summaries['payouts'] ?? null);
        $this->assertSame([$this->superadmin->id], AuditLog::query()->where('entity', 'Module')->pluck('userId')->unique()->values()->all());
        $this->assertSame(3, AuditLog::query()->where('entity', 'Module')->count());
    }

    // ---- the superadmin role itself -------------------------------------------------------------------------

    public function test_a_superadmin_can_do_everything_an_admin_can(): void
    {
        $this->assertTrue($this->superadmin->isAdmin());
        $this->assertTrue($this->superadmin->isSuperAdmin());
        $this->assertFalse($this->w->admin->isSuperAdmin());
        $this->assertNull(app(PropertyScope::class)->visibleIds($this->superadmin), 'sees every property');

        $this->as($this->superadmin)->getJson('/api/v1/properties')->assertOk()->assertJsonCount(2, 'data');
        $this->as($this->superadmin)->getJson('/api/v1/users')->assertOk();
        $this->as($this->superadmin)->getJson('/api/v1/audit')->assertOk();
        $this->as($this->superadmin)->putJson('/api/v1/settings/organization', ['name' => 'Acme PM'])->assertOk();
    }

    public function test_the_permission_matrix_gives_a_superadmin_what_an_admin_has_plus_module_control(): void
    {
        $perms = $this->as($this->w->managerA)->getJson('/api/v1/rbac')->assertOk()->json('data');

        $this->assertContains('superadmin', $perms['roles']);
        $this->assertEqualsCanonicalizing([...$perms['permissions']['admin'], 'manage_modules'], $perms['permissions']['superadmin']);
        $this->assertNotContains('manage_modules', $perms['permissions']['admin']);
        foreach ($perms['resources'] as $resource => $rules) {
            foreach ($rules as $kind => $roles) {
                $this->assertSame(in_array('admin', $roles, true), in_array('superadmin', $roles, true), "{$resource}.{$kind}");
            }
        }
    }

    public function test_a_superadmin_account_is_not_listed_to_people_who_are_not_admins(): void
    {
        $manager = collect($this->as($this->w->managerA)->getJson('/api/v1/bootstrap')->json('data.users'))->pluck('role');
        $this->assertNotContains('superadmin', $manager->all());
        $this->assertNotContains('admin', $manager->all());

        $admin = collect($this->as($this->w->admin)->getJson('/api/v1/bootstrap')->json('data.users'))->pluck('role');
        $this->assertContains('superadmin', $admin->all());
    }

    public function test_a_superadmin_keeps_the_role_when_memberships_are_recomputed(): void
    {
        $this->assertSame('superadmin', $this->superadmin->recomputeRole());
        $this->assertSame('superadmin', $this->superadmin->fresh()->role);
    }
}

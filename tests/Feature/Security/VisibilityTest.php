<?php

namespace Tests\Feature\Security;

use App\Models\PropertyMember;
use App\Models\Tenant;
use App\Services\PropertyScope;
use Tests\PmsTestCase;
use Tests\Support\World;

/** Access to property data is granted only by an *active* membership; everything else sees nothing. */
class VisibilityTest extends PmsTestCase
{
    private function ids(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_each_owner_sees_only_their_own_property_data(): void
    {
        $this->as($this->w->ownerA);
        $this->assertSame(['prop_a'], $this->ids('/api/v1/properties'));
        $this->assertSame(['bld_a'], $this->ids('/api/v1/buildings'));
        $this->assertSame(['unit_a'], $this->ids('/api/v1/units'));
        $this->assertContains($this->w->invoice->id, $this->ids('/api/v1/invoices'));

        $this->as($this->w->ownerB);
        $this->assertSame(['prop_b'], $this->ids('/api/v1/properties'));
        $this->assertSame([], $this->ids('/api/v1/invoices'));
        $this->assertSame(['lease_b'], $this->ids('/api/v1/leases'));        // their own only, never lease_a
    }

    public function test_an_owner_cannot_open_another_portfolios_records_by_id(): void
    {
        $this->as($this->w->ownerB);

        foreach (['properties/prop_a', 'buildings/bld_a', 'units/unit_a', 'leases/lease_a', "invoices/{$this->w->invoice->id}"] as $path) {
            $status = $this->getJson("/api/v1/{$path}")->status();
            $this->assertContains($status, [403, 404], "GET {$path} returned {$status}");
        }
    }

    public function test_a_registered_user_with_no_membership_sees_nothing(): void
    {
        $this->as($this->w->stranger);

        foreach (['properties', 'buildings', 'units', 'leases', 'invoices', 'payments', 'tenants'] as $resource) {
            $status = $this->getJson("/api/v1/{$resource}")->status();
            if ($status === 200) {
                $this->assertSame([], $this->ids("/api/v1/{$resource}"), "{$resource} leaked to a user with no membership");
            } else {
                $this->assertSame(403, $status);
            }
        }
    }

    public function test_a_pending_invitation_grants_no_access_until_accepted(): void
    {
        $invitee = World::user('manager', 'invitee@pms.test');
        World::member($this->w->pB, $invitee, 'manager', null, 'invited');

        $this->as($invitee);
        $this->assertSame([], $this->ids('/api/v1/properties'));
    }

    public function test_a_revoked_member_loses_access(): void
    {
        $this->as($this->w->managerA);
        $this->assertSame(['prop_a'], $this->ids('/api/v1/properties'));

        PropertyMember::query()->where('userId', $this->w->managerA->id)->update(['status' => 'revoked']);
        // PropertyScope caches memberships per request; dropping the singleton models the next request.
        $this->app->forgetInstance(PropertyScope::class);

        $this->as($this->w->managerA);
        $this->assertSame([], $this->ids('/api/v1/properties'));
    }

    public function test_a_tenant_sees_only_their_own_invoices(): void
    {
        $other = Tenant::query()->create(['id' => 'ten_bob', 'name' => 'Bob', 'email' => 'bob@tenant.test', 'createdAt' => now()->toDateString()]);
        $bob = World::user('tenant', 'bob@tenant.test');
        World::member($this->w->pA, $bob, 'tenant', $other->id);
        $bobsInvoice = $this->w->invoice(['id' => 'inv_bob', 'tenantId' => 'ten_bob']);

        $this->as($this->w->tenantUser);
        $mine = $this->ids('/api/v1/invoices');
        $this->assertContains($this->w->invoice->id, $mine);
        $this->assertNotContains($bobsInvoice->id, $mine);
        $this->assertContains($this->getJson("/api/v1/invoices/{$bobsInvoice->id}")->status(), [403, 404]);

        $this->as($bob);
        $this->assertSame(['inv_bob'], $this->ids('/api/v1/invoices'));
    }

    public function test_a_tenant_cannot_edit_or_delete_invoices(): void
    {
        $this->as($this->w->tenantUser);

        $this->putJson("/api/v1/invoices/{$this->w->invoice->id}", ['total' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/invoices/{$this->w->invoice->id}")->assertForbidden();
        $this->assertSame(100_000, (int) $this->w->invoice->fresh()->total);
    }

    public function test_the_audit_log_is_for_admin_and_finance_only(): void
    {
        foreach (['ownerA', 'managerA', 'tenantUser', 'stranger'] as $who) {
            $status = $this->as($this->w->{$who})->getJson('/api/v1/audit')->status();
            $this->assertContains($status, [200, 403], "{$who}: audit returned {$status}");
            if ($status === 200) {
                $this->assertSame([], $this->ids('/api/v1/audit'), "{$who} can read the audit log");
            }
        }
    }

    public function test_the_bootstrap_payload_is_scoped_to_the_callers_properties(): void
    {
        $this->as($this->w->ownerB);
        $payload = $this->getJson('/api/v1/bootstrap')->assertOk()->json('data');

        $this->assertSame(['prop_b'], collect($payload['properties'])->pluck('id')->all());
        $this->assertSame([], $payload['invoices']);
        $this->assertStringNotContainsString('Alpha Court', json_encode($payload));
    }
}

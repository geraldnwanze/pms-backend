<?php

namespace Tests\Feature\Security;

use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\PropertyMember;
use App\Models\Vendor;
use Tests\PmsTestCase;
use Tests\Support\World;

/**
 * A manager of property A holds the *role* to manage expenses, maintenance, meters… — but only on
 * property A. For every property-scoped resource: no creating on B, no editing or deleting B's
 * records, no moving A's records to B, and no linking A's records to B's units.
 */
class CrossPropertyWriteTest extends PmsTestCase
{
    /** @return array<string, array{0:string,1:callable(string,string):array}> resource => [payload(propertyId, unitId)] */
    private function resources(): array
    {
        return [
            'expenses' => fn ($p, $u) => ['propertyId' => $p, 'category' => 'repairs', 'description' => 'Pump', 'amount' => 5_000, 'date' => '2026-11-01'],
            'maintenance' => fn ($p, $u) => ['propertyId' => $p, 'unitId' => $u, 'title' => 'Leak', 'category' => 'plumbing', 'urgency' => 'low'],
            'inspections' => fn ($p, $u) => ['propertyId' => $p, 'unitId' => $u, 'type' => 'routine', 'scheduledDate' => '2026-12-01'],
            'meters' => fn ($p, $u) => ['propertyId' => $p, 'unitId' => $u, 'type' => 'water', 'meterNumber' => 'M-'.$p, 'unitOfMeasure' => 'm3'],
            'listings' => fn ($p, $u) => ['propertyId' => $p, 'unitId' => $u, 'title' => 'Nice flat', 'price' => 900_000],
            'leads' => fn ($p, $u) => ['propertyId' => $p, 'name' => 'Lee', 'email' => 'lee@x.test', 'phone' => '080'],
            'documents' => fn ($p, $u) => ['propertyId' => $p, 'name' => 'Deed', 'category' => 'deed', 'fileType' => 'pdf'],
            'notices' => fn ($p, $u) => ['propertyId' => $p, 'title' => 'Water off', 'body' => 'Tomorrow', 'audience' => 'all'],
            'tasks' => fn ($p, $u) => ['propertyId' => $p, 'title' => 'Call', 'dueDate' => '2026-12-01', 'priority' => 'low'],
        ];
    }

    /** Resources whose create needs a unit of the property. */
    private function usesUnit(string $resource): bool
    {
        return in_array($resource, ['maintenance', 'inspections', 'meters', 'listings'], true);
    }

    private function adminCreatesOnB(string $resource): string
    {
        $make = $this->resources()[$resource];

        return $this->as($this->w->admin)->postJson("/api/v1/{$resource}", $make('prop_b', 'unit_b'))
            ->assertCreated()->json('data.id');
    }

    public function test_a_manager_cannot_create_on_a_property_they_are_not_part_of(): void
    {
        foreach ($this->resources() as $resource => $make) {
            $this->as($this->w->managerA)->postJson("/api/v1/{$resource}", $make('prop_b', 'unit_b'))
                ->assertForbidden("creating {$resource} on property B");
        }
    }

    public function test_a_manager_can_create_on_their_own_property(): void
    {
        foreach ($this->resources() as $resource => $make) {
            $this->as($this->w->managerA)->postJson("/api/v1/{$resource}", $make('prop_a', 'unit_a'))
                ->assertCreated("creating {$resource} on property A");
        }
    }

    public function test_a_record_cannot_be_linked_to_a_unit_of_another_property(): void
    {
        $leaks = [];
        foreach ($this->resources() as $resource => $make) {
            if (! $this->usesUnit($resource)) {
                continue;
            }
            $status = $this->as($this->w->managerA)->postJson("/api/v1/{$resource}", $make('prop_a', 'unit_b'))->status();
            if ($status !== 422) {
                $leaks[] = "{$resource} ({$status})";
            }
        }

        $this->assertSame([], $leaks, "created on A but pointing at B's unit");
    }

    public function test_a_manager_cannot_edit_or_delete_another_propertys_records(): void
    {
        foreach (array_keys($this->resources()) as $resource) {
            $id = $this->adminCreatesOnB($resource);
            $this->as($this->w->managerA);

            $this->assertContains($this->putJson("/api/v1/{$resource}/{$id}", ['title' => 'Hijacked', 'description' => 'Hijacked', 'name' => 'Hijacked'])->status(), [403, 404], "editing {$resource}");
            $this->assertContains($this->deleteJson("/api/v1/{$resource}/{$id}")->status(), [403, 404], "deleting {$resource}");
            $this->getJson("/api/v1/{$resource}/{$id}")->assertStatus($this->getJson("/api/v1/{$resource}/{$id}")->status() === 404 ? 404 : 403);
        }
    }

    public function test_a_record_cannot_be_moved_to_another_property(): void
    {
        $leaks = [];
        foreach ($this->resources() as $resource => $make) {
            $id = $this->as($this->w->managerA)->postJson("/api/v1/{$resource}", $make('prop_a', 'unit_a'))->assertCreated()->json('data.id');

            $status = $this->putJson("/api/v1/{$resource}/{$id}", ['propertyId' => 'prop_b'])->status();
            if ($status !== 422) {
                $leaks[] = "{$resource} ({$status})";
            }
        }

        $this->assertSame([], $leaks, 'moved to property B');
    }

    // ---- Links to people and portfolios ------------------------------------

    public function test_a_record_cannot_be_linked_to_a_tenant_outside_the_actors_portfolio(): void
    {
        $this->w->tenantB->forceFill(['ownerId' => $this->w->ownerB->id])->save();
        $this->w->tenant->forceFill(['ownerId' => $this->w->ownerA->id])->save();
        $this->as($this->w->managerA);

        $doc = ['propertyId' => 'prop_a', 'name' => 'ID', 'category' => 'tenant-id', 'fileType' => 'pdf'];
        $this->postJson('/api/v1/documents', $doc + ['tenantId' => 'ten_bee'])->assertStatus(422);
        $this->postJson('/api/v1/documents', $doc + ['tenantId' => 'ten_ada'])->assertCreated();

        $job = ['propertyId' => 'prop_a', 'unitId' => 'unit_a', 'title' => 'Leak', 'category' => 'plumbing', 'urgency' => 'low'];
        $this->postJson('/api/v1/maintenance', $job + ['tenantId' => 'ten_bee'])->assertStatus(422);
        $this->postJson('/api/v1/maintenance', $job + ['tenantId' => 'ten_ada'])->assertCreated();
    }

    public function test_a_new_tenant_filed_under_the_managers_own_id_can_be_used_before_any_lease_exists(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/tenants', [
            'name' => 'New Tenant', 'email' => 'new@tenant.test', 'phone' => '080', 'identityType' => 'nin',
            'identityNumber' => '123', 'ownerId' => $this->w->managerA->id,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/documents', ['propertyId' => 'prop_a', 'name' => 'ID', 'category' => 'tenant-id', 'fileType' => 'pdf', 'tenantId' => $id])
            ->assertCreated();
    }

    public function test_a_lead_cannot_point_at_a_listing_of_another_property(): void
    {
        $listingB = $this->adminCreatesOnB('listings');

        $this->as($this->w->managerA)->postJson('/api/v1/leads', ['name' => 'Lee', 'email' => 'lee@x.test', 'phone' => '080', 'listingId' => $listingB])
            ->assertStatus(422);
        $this->postJson('/api/v1/leads', ['name' => 'Lee', 'email' => 'lee@x.test', 'phone' => '080', 'propertyId' => 'prop_a', 'listingId' => $listingB])
            ->assertStatus(422);

        // an admin sees both properties, so only the property match can refuse this one
        $this->as($this->w->admin)
            ->postJson('/api/v1/leads', ['name' => 'Lee', 'email' => 'lee@x.test', 'phone' => '080', 'propertyId' => 'prop_a', 'listingId' => $listingB])
            ->assertStatus(422);
    }

    // ---- Resources that must not be written freely --------------------------

    public function test_payments_cannot_be_written_through_the_generic_routes(): void
    {
        $invoice = $this->w->invoice(['id' => 'inv_b', 'leaseId' => 'lease_b', 'unitId' => 'unit_b', 'propertyId' => 'prop_b', 'tenantId' => 'ten_bee']);
        $pay = ['invoiceId' => $invoice->id, 'type' => 'rent', 'method' => 'cash', 'amount' => 100, 'paymentDate' => '2026-11-01', 'reference' => 'R1'];

        foreach (['tenantUser', 'managerA', 'financeA', 'admin'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/payments', $pay)->assertStatus(405);
        }

        // ... and an existing payment cannot be rewritten or deleted that way either
        $own = $this->as($this->w->tenantUser)->postJson('/api/v1/payments/submit', [
            'invoiceId' => $this->w->invoice->id, 'type' => 'rent', 'method' => 'cash', 'amount' => 1_000, 'paymentDate' => '2026-11-01', 'reference' => 'R2',
        ])->assertCreated()->json('data.id');

        foreach (['managerA', 'financeA', 'admin'] as $who) {
            $this->as($this->w->{$who});
            $this->putJson("/api/v1/payments/{$own}", ['amount' => 999_999])->assertStatus(405);
            $this->deleteJson("/api/v1/payments/{$own}")->assertStatus(405);
        }
        $this->assertSame(1_000, (int) Payment::query()->findOrFail($own)->amount);
    }

    public function test_a_message_is_always_from_the_person_sending_it(): void
    {
        $this->as($this->w->tenantUser)
            ->postJson('/api/v1/messages', ['threadId' => 't1', 'fromUserId' => $this->w->ownerB->id, 'toUserId' => $this->w->ownerA->id, 'body' => 'pay me'])
            ->assertCreated()
            ->assertJsonPath('data.fromUserId', $this->w->tenantUser->id);
    }

    public function test_only_admins_can_create_arbitrary_notifications(): void
    {
        $note = ['kind' => 'system', 'title' => 'Verify your account', 'body' => 'Click here', 'forRole' => 'owner', 'link' => 'https://evil.test'];

        foreach (['tenantUser', 'managerA', 'ownerA', 'stranger'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/notifications', $note)->assertForbidden();
        }
        $this->as($this->w->admin)->postJson('/api/v1/notifications', $note)->assertCreated();
    }

    public function test_a_vendor_can_only_be_filed_under_a_portfolio_the_actor_belongs_to(): void
    {
        $vendor = fn (string $owner) => ['name' => 'Pipes Ltd', 'service' => 'plumbing', 'phone' => '080', 'email' => 'p@x.test', 'ownerId' => $owner];

        $this->as($this->w->managerA);
        $this->postJson('/api/v1/vendors', $vendor($this->w->ownerB->id))->assertForbidden();
        $this->postJson('/api/v1/vendors', $vendor($this->w->ownerA->id))->assertCreated();
        $id = $this->postJson('/api/v1/vendors', $vendor($this->w->managerA->id))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/vendors/{$id}", ['ownerId' => $this->w->ownerB->id])->assertStatus(422);
    }

    // ---- People and vendors -------------------------------------------------

    /** An agent on property A and an agent on property B. */
    private function agents(): array
    {
        $a = World::user('agent', 'agent.a@pms.test');
        $b = World::user('agent', 'agent.b@pms.test');
        World::member($this->w->pA, $a, 'agent');
        World::member($this->w->pB, $b, 'agent');

        return [$a, $b];
    }

    private function job(array $over = []): array
    {
        return $over + ['propertyId' => 'prop_a', 'unitId' => 'unit_a', 'title' => 'Leak', 'category' => 'plumbing', 'urgency' => 'low'];
    }

    public function test_work_cannot_be_assigned_to_someone_who_is_not_on_the_property(): void
    {
        [$a, $b] = $this->agents();
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/maintenance', $this->job(['assignedToId' => $b->id]))->assertStatus(422);
        $id = $this->postJson('/api/v1/maintenance', $this->job(['assignedToId' => $a->id]))->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/maintenance/{$id}", ['assignedToId' => $b->id])->assertStatus(422);
        $this->assertSame($a->id, MaintenanceRequest::query()->find($id)->assignedToId);
    }

    public function test_inspectors_agents_and_task_assignees_must_belong_to_the_property_or_be_the_actor(): void
    {
        [$a, $b] = $this->agents();
        $this->as($this->w->managerA);

        $inspection = ['propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => 'routine', 'scheduledDate' => '2026-12-01'];
        $this->postJson('/api/v1/inspections', $inspection + ['inspectorId' => $b->id])->assertStatus(422);
        $this->postJson('/api/v1/inspections', $inspection + ['inspectorId' => $a->id])->assertCreated();

        $listing = ['propertyId' => 'prop_a', 'unitId' => 'unit_a', 'title' => 'Flat', 'price' => 1];
        $this->postJson('/api/v1/listings', $listing + ['agentId' => $b->id])->assertStatus(422);
        $this->postJson('/api/v1/listings', $listing + ['agentId' => $a->id])->assertCreated();

        $lead = ['name' => 'Lee', 'email' => 'lee@x.test', 'phone' => '080', 'propertyId' => 'prop_a'];
        $this->postJson('/api/v1/leads', $lead + ['agentId' => $b->id])->assertStatus(422);
        $this->postJson('/api/v1/leads', $lead + ['agentId' => $a->id])->assertCreated();

        // a task with no property: anyone on a property the actor can see, themselves, or an admin — not a stranger's agent
        $task = ['title' => 'Call', 'dueDate' => '2026-12-01', 'priority' => 'low'];
        $this->postJson('/api/v1/tasks', $task + ['assignedToId' => $b->id])->assertStatus(422);
        $this->postJson('/api/v1/tasks', $task + ['assignedToId' => $a->id])->assertCreated();
        $this->postJson('/api/v1/tasks', $task + ['assignedToId' => $this->w->managerA->id])->assertCreated();
        $this->postJson('/api/v1/tasks', $task + ['assignedToId' => $this->w->admin->id])->assertCreated();
    }

    public function test_a_member_who_was_revoked_cannot_be_newly_assigned(): void
    {
        [$a] = $this->agents();
        PropertyMember::query()->where('userId', $a->id)->update(['status' => 'revoked']);

        $this->as($this->w->managerA)->postJson('/api/v1/maintenance', $this->job(['assignedToId' => $a->id]))->assertStatus(422);
    }

    public function test_a_vendor_from_another_portfolio_cannot_be_attached(): void
    {
        $theirs = Vendor::query()->create(['id' => 'vnd_b', 'name' => 'B Pipes', 'service' => 'plumbing', 'phone' => '1', 'email' => 'b@x.test', 'ownerId' => $this->w->ownerB->id, 'totalPaid' => 0, 'outstanding' => 0]);
        $mine = Vendor::query()->create(['id' => 'vnd_a', 'name' => 'A Pipes', 'service' => 'plumbing', 'phone' => '1', 'email' => 'a@x.test', 'ownerId' => $this->w->ownerA->id, 'totalPaid' => 0, 'outstanding' => 0]);
        $expense = ['propertyId' => 'prop_a', 'category' => 'repairs', 'description' => 'Pump', 'amount' => 5_000, 'date' => '2026-11-01'];
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/maintenance', $this->job(['vendorId' => $theirs->id]))->assertStatus(422);
        $this->postJson('/api/v1/expenses', $expense + ['vendorId' => $theirs->id])->assertStatus(422);
        $this->postJson('/api/v1/maintenance', $this->job(['vendorId' => $mine->id]))->assertCreated();
        $this->postJson('/api/v1/expenses', $expense + ['vendorId' => $mine->id])->assertCreated();
    }

    public function test_a_unit_can_only_list_meters_of_its_own_property(): void
    {
        $meterA = $this->as($this->w->managerA)->postJson('/api/v1/meters', ['propertyId' => 'prop_a', 'type' => 'water', 'meterNumber' => 'MA', 'unitOfMeasure' => 'm3'])->assertCreated()->json('data.id');
        $meterB = $this->adminCreatesOnB('meters');

        $this->as($this->w->managerA);
        $this->patchJson('/api/v1/units/unit_a', ['meterIds' => [$meterB]])->assertStatus(422);
        $this->patchJson('/api/v1/units/unit_a', ['meterIds' => [$meterA, $meterB]])->assertStatus(422);
        $this->patchJson('/api/v1/units/unit_a', ['meterIds' => [$meterA]])->assertOk();
    }

    public function test_resending_an_unchanged_stale_link_does_not_block_an_edit(): void
    {
        [, $b] = $this->agents();
        $job = MaintenanceRequest::query()->create($this->job(['id' => 'mnt_old', 'code' => 'MNT-OLD', 'status' => 'open', 'assignedToId' => $b->id]));

        // the SPA PATCHes whole objects; the assignee is untouched, so only the title is really changing
        $this->as($this->w->managerA)->patchJson("/api/v1/maintenance/{$job->id}", $this->job(['title' => 'Leak (updated)', 'assignedToId' => $b->id]))
            ->assertOk()->assertJsonPath('data.title', 'Leak (updated)');
    }

    // ---- The same rules on the workflow endpoint the app really uses --------------

    private function openJob(array $over = []): MaintenanceRequest
    {
        return MaintenanceRequest::query()->create($this->job($over) + ['id' => 'mnt_t'.uniqid(), 'code' => 'MNT-T', 'status' => 'open', 'createdAt' => now()->toIso8601String()]);
    }

    public function test_assigning_through_a_transition_also_needs_someone_on_the_property(): void
    {
        [$a, $b] = $this->agents();
        $job = $this->openJob();

        $this->as($this->w->managerA)->postJson("/api/v1/maintenance/{$job->id}/transition", ['status' => 'assigned', 'assignedToId' => $b->id])->assertStatus(422);
        $this->assertNull($job->fresh()->assignedToId);
        $this->assertSame('open', $job->fresh()->status);

        $this->postJson("/api/v1/maintenance/{$job->id}/transition", ['status' => 'assigned', 'assignedToId' => $a->id])->assertOk();
        $this->assertSame($a->id, $job->fresh()->assignedToId);

        $this->postJson("/api/v1/maintenance/{$job->id}/transition", ['status' => 'assigned', 'assignedToId' => $b->id])->assertStatus(422);
        $this->assertSame($a->id, $job->fresh()->assignedToId, 'a failed reassignment leaves the technician as they were');
    }

    public function test_an_owner_of_two_properties_cannot_assign_one_propertys_technician_to_the_others_work(): void
    {
        // the app lists every technician the owner can see; only the work order's own property counts
        $owner = World::user('owner', 'two.props@pms.test');
        World::member($this->w->pA, $owner, 'owner');
        World::member($this->w->pB, $owner, 'owner');
        [, $onlyB] = $this->agents();
        $job = $this->openJob();

        $this->as($owner)->postJson("/api/v1/maintenance/{$job->id}/transition", ['status' => 'assigned', 'assignedToId' => $onlyB->id])->assertStatus(422);
    }

    public function test_the_current_technician_can_stay_even_if_they_have_since_left(): void
    {
        [, $b] = $this->agents();
        $job = $this->openJob(['status' => 'in-progress', 'assignedToId' => $b->id]);

        $this->as($this->w->managerA)->postJson("/api/v1/maintenance/{$job->id}/transition", ['status' => 'in-progress', 'assignedToId' => $b->id, 'labourCost' => 1_000])->assertOk();
    }

    public function test_a_vendor_chosen_through_a_transition_must_be_reachable_and_only_assigners_can_choose(): void
    {
        $theirs = Vendor::query()->create(['id' => 'vnd_b', 'name' => 'B Pipes', 'service' => 'x', 'phone' => '1', 'email' => 'b@x.test', 'ownerId' => $this->w->ownerB->id, 'totalPaid' => 0, 'outstanding' => 0]);
        $mine = Vendor::query()->create(['id' => 'vnd_a', 'name' => 'A Pipes', 'service' => 'x', 'phone' => '1', 'email' => 'a@x.test', 'ownerId' => $this->w->ownerA->id, 'totalPaid' => 0, 'outstanding' => 0]);
        $job = $this->openJob();

        $this->as($this->w->managerA)->postJson("/api/v1/maintenance/{$job->id}/transition", ['status' => 'assigned', 'vendorId' => $theirs->id])->assertStatus(422);
        $this->postJson("/api/v1/maintenance/{$job->id}/transition", ['status' => 'assigned', 'vendorId' => $mine->id])->assertOk();

        // a technician working the job cannot hand it to a vendor
        $tech = World::user('maintenance', 'tech.v@pms.test');
        World::member($this->w->pA, $tech, 'maintenance');
        $other = $this->openJob(['status' => 'assigned', 'assignedToId' => $tech->id]);
        $this->as($tech)->postJson("/api/v1/maintenance/{$other->id}/transition", ['status' => 'in-progress', 'vendorId' => $mine->id])->assertForbidden();
        $this->assertNull($other->fresh()->vendorId);
    }
}

<?php

namespace Tests\Feature\Workflow;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;
use Tests\Support\World;

/**
 * Statuses are moved by the workflow actions, never written directly through the generic create /
 * update routes — otherwise an approval, a tenant's confirmation or a lease's wind-down can be skipped.
 */
class StatusIntegrityTest extends PmsTestCase
{
    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');

        $this->tech = World::user('maintenance', 'tech.a@pms.test');
        World::member($this->w->pA, $this->tech, 'maintenance');

        foreach (['unit_free', 'unit_free2'] as $id) {
            Unit::query()->create(['id' => $id, 'buildingId' => 'bld_a', 'propertyId' => 'prop_a', 'code' => strtoupper($id), 'type' => 'Flat', 'status' => 'available', 'rent' => 1]);
        }
    }

    // ======================= Expenses ==========================================

    private function expense(array $over = []): array
    {
        return $over + ['propertyId' => 'prop_a', 'category' => 'repairs', 'description' => 'Pump', 'amount' => 5_000, 'date' => '2026-11-01'];
    }

    private function pendingExpense(): string
    {
        return $this->as($this->w->managerA)->postJson('/api/v1/expenses', $this->expense())->assertCreated()->json('data.id');
    }

    private function setExpense(User $by, string $id, string $status)
    {
        return $this->as($by)->postJson("/api/v1/expenses/{$id}/status", ['status' => $status]);
    }

    public function test_an_expense_is_always_created_pending_whatever_the_client_sends(): void
    {
        foreach (['managerA', 'financeA'] as $who) {
            foreach (['approved', 'paid', 'rejected'] as $status) {
                $this->as($this->w->{$who})->postJson('/api/v1/expenses', $this->expense(['status' => $status]))
                    ->assertCreated()
                    ->assertJsonPath('data.status', 'pending')
                    ->assertJsonPath('data.approvedById', null);
            }
        }
    }

    public function test_an_expense_cannot_be_approved_or_paid_through_a_plain_edit(): void
    {
        $id = $this->pendingExpense();

        foreach (['approved', 'paid'] as $status) {
            $this->as($this->w->managerA)->patchJson("/api/v1/expenses/{$id}", ['status' => $status])->assertStatus(422);
        }
        $this->assertSame('pending', Expense::query()->find($id)->status);
    }

    public function test_a_pending_expense_can_be_corrected_but_a_decided_one_is_locked(): void
    {
        $id = $this->pendingExpense();

        $this->as($this->w->managerA)->patchJson("/api/v1/expenses/{$id}", $this->expense(['amount' => 7_500, 'status' => 'pending']))
            ->assertOk()->assertJsonPath('data.amount', 7_500);

        $this->setExpense($this->w->managerA, $id, 'approved')->assertOk();
        $this->as($this->w->managerA)->patchJson("/api/v1/expenses/{$id}", ['amount' => 1])->assertStatus(422);
        $this->as($this->w->financeA)->patchJson("/api/v1/expenses/{$id}", ['description' => 'Changed after approval'])->assertStatus(422);

        $this->setExpense($this->w->financeA, $id, 'paid')->assertOk();
        $this->as($this->w->financeA)->patchJson("/api/v1/expenses/{$id}", ['amount' => 1])->assertStatus(422);

        $this->assertSame(7_500, (int) Expense::query()->find($id)->amount, 'a paid amount must not be rewritten');
    }

    public function test_an_expense_follows_pending_approved_paid_and_records_who_decided(): void
    {
        $id = $this->pendingExpense();

        $this->setExpense($this->w->managerA, $id, 'approved')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame($this->w->managerA->id, Expense::query()->find($id)->approvedById);

        $this->setExpense($this->w->financeA, $id, 'paid')->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame($this->w->managerA->id, Expense::query()->find($id)->approvedById, 'paying does not replace the approver');
    }

    public function test_money_cannot_move_before_approval_and_paid_is_final(): void
    {
        $id = $this->pendingExpense();

        $this->setExpense($this->w->financeA, $id, 'paid')->assertStatus(422);          // not approved yet
        $this->setExpense($this->w->managerA, $id, 'pending')->assertStatus(422);       // no-op transition

        $this->setExpense($this->w->managerA, $id, 'approved')->assertOk();
        $this->setExpense($this->w->financeA, $id, 'paid')->assertOk();

        foreach (['pending', 'approved', 'rejected'] as $status) {
            $this->setExpense($this->w->financeA, $id, $status)->assertStatus(422);
        }
        $this->assertSame('paid', Expense::query()->find($id)->status);
    }

    public function test_a_rejected_expense_can_be_resubmitted_but_not_paid(): void
    {
        $id = $this->pendingExpense();
        $this->setExpense($this->w->managerA, $id, 'rejected')->assertOk();

        $this->setExpense($this->w->financeA, $id, 'paid')->assertStatus(422);
        $this->setExpense($this->w->managerA, $id, 'approved')->assertStatus(422);

        $this->setExpense($this->w->managerA, $id, 'pending')->assertOk();
        $this->assertNull(Expense::query()->find($id)->approvedById, 'a resubmitted expense has no decision on it');
    }

    public function test_only_people_who_can_approve_may_approve_or_reject(): void
    {
        $id = $this->pendingExpense();

        // owners oversee but hold neither edit nor approve
        $this->setExpense($this->w->ownerA, $id, 'approved')->assertForbidden();
        $this->setExpense($this->w->ownerA, $id, 'rejected')->assertForbidden();
        $this->setExpense($this->w->tenantUser, $id, 'approved')->assertForbidden();
        $this->setExpense($this->w->ownerB, $id, 'approved')->assertForbidden();

        $this->assertSame('pending', Expense::query()->find($id)->status);
    }

    // ======================= Maintenance =======================================

    private function job(array $over = []): array
    {
        return $over + ['propertyId' => 'prop_a', 'unitId' => 'unit_a', 'tenantId' => 'ten_ada', 'title' => 'Leak', 'category' => 'plumbing', 'urgency' => 'low'];
    }

    private function workOrder(string $status, array $over = []): MaintenanceRequest
    {
        return MaintenanceRequest::query()->create($this->job($over) + [
            'id' => 'mnt_'.uniqid(), 'code' => 'MNT-'.strtoupper(substr(uniqid(), -5)), 'status' => $status,
            'assignedToId' => $this->tech->id, 'createdAt' => now()->toIso8601String(),
        ]);
    }

    private function move(User $by, MaintenanceRequest $m, string $status, array $extra = [])
    {
        return $this->as($by)->postJson("/api/v1/maintenance/{$m->id}/transition", ['status' => $status] + $extra);
    }

    public function test_a_request_is_always_born_open_and_a_tenant_cannot_set_the_costs(): void
    {
        foreach (['closed', 'verified', 'assigned', 'completed'] as $status) {
            $this->as($this->w->tenantUser)->postJson('/api/v1/maintenance', $this->job(['status' => $status, 'materialsCost' => 999_999, 'labourCost' => 999_999]))
                ->assertCreated()
                ->assertJsonPath('data.status', 'open')
                ->assertJsonPath('data.materialsCost', 0)
                ->assertJsonPath('data.labourCost', 0);
        }
    }

    public function test_a_manager_may_record_costs_and_assigning_at_creation_makes_it_assigned(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/maintenance', $this->job(['materialsCost' => 12_000, 'assignedToId' => $this->tech->id, 'status' => 'closed']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.materialsCost', 12_000);
    }

    public function test_status_cannot_be_changed_through_a_plain_edit(): void
    {
        $m = $this->workOrder('open');

        foreach (['managerA' => 'verified', 'tech' => 'verified', 'admin' => 'closed'] as $who => $status) {
            $by = $who === 'tech' ? $this->tech : $this->w->{$who};
            $this->as($by)->patchJson("/api/v1/maintenance/{$m->id}", ['status' => $status])->assertStatus(422);
        }
        $this->assertSame('open', $m->fresh()->status);
    }

    public function test_a_plain_edit_that_restates_the_status_still_works(): void
    {
        $m = $this->workOrder('open');

        $this->as($this->w->managerA)->patchJson("/api/v1/maintenance/{$m->id}", $this->job(['status' => 'open', 'title' => 'Leak (updated)']))
            ->assertOk()->assertJsonPath('data.title', 'Leak (updated)');
    }

    public function test_nobody_but_the_tenant_can_mark_work_confirmed(): void
    {
        $m = $this->workOrder('completed');

        foreach (['tech', 'managerA', 'admin'] as $who) {
            $by = $who === 'tech' ? $this->tech : $this->w->{$who};
            $this->move($by, $m, 'tenant-confirmed')->assertStatus(422);
        }
        $this->assertSame('completed', $m->fresh()->status);

        $this->as($this->w->tenantUser)->postJson("/api/v1/maintenance/{$m->id}/confirm")->assertOk()->assertJsonPath('data.status', 'tenant-confirmed');
    }

    public function test_the_happy_path_runs_open_to_closed(): void
    {
        $m = $this->workOrder('open', ['assignedToId' => null]);

        $this->move($this->w->managerA, $m, 'assigned', ['assignedToId' => $this->tech->id])->assertOk();
        $this->move($this->tech, $m, 'in-progress')->assertOk();
        $this->move($this->tech, $m, 'completed', ['materialsCost' => 3_000, 'labourCost' => 2_000])->assertOk();
        $this->as($this->w->tenantUser)->postJson("/api/v1/maintenance/{$m->id}/confirm")->assertOk();
        $this->move($this->w->managerA, $m, 'verified')->assertOk();
        $this->move($this->w->managerA, $m, 'closed')->assertOk();

        $fresh = $m->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertSame(3_000, (int) $fresh->materialsCost);
        $this->assertNotNull($fresh->completedAt);
        $this->assertNotNull($fresh->closedAt);
        $this->assertSame($this->w->managerA->id, $fresh->verifiedById);
    }

    public function test_steps_cannot_be_skipped_or_reversed(): void
    {
        $skips = [
            ['open', 'in-progress'], ['open', 'completed'], ['open', 'verified'],
            ['assigned', 'completed'], ['assigned', 'verified'],
            ['in-progress', 'verified'], ['in-progress', 'open'],
            ['completed', 'open'], ['completed', 'assigned'], ['completed', 'verified'],
            ['verified', 'open'], ['verified', 'in-progress'], ['verified', 'completed'],
            ['closed', 'open'], ['closed', 'assigned'], ['closed', 'in-progress'], ['closed', 'verified'],
        ];

        foreach ($skips as [$from, $to]) {
            $m = $this->workOrder($from);
            $this->move($this->w->managerA, $m, $to)->assertStatus(422, "{$from} → {$to}");
            $this->assertSame($from, $m->fresh()->status, "{$from} → {$to} must not happen");
        }
    }

    public function test_rework_and_reassignment_are_allowed(): void
    {
        $this->move($this->w->managerA, $this->workOrder('completed'), 'in-progress')->assertOk();
        $this->move($this->w->managerA, $this->workOrder('tenant-confirmed'), 'in-progress')->assertOk();
        $this->move($this->w->managerA, $this->workOrder('assigned'), 'assigned', ['assignedToId' => $this->tech->id])->assertOk();
        $this->move($this->w->managerA, $this->workOrder('assigned'), 'open')->assertOk();
        $this->move($this->w->managerA, $this->workOrder('open'), 'closed')->assertOk();         // cancelled
    }

    public function test_costs_can_be_updated_without_changing_status(): void
    {
        $m = $this->workOrder('in-progress');

        $this->move($this->tech, $m, 'in-progress', ['labourCost' => 4_000])->assertOk();

        $this->assertSame(4_000, (int) $m->fresh()->labourCost);
    }

    public function test_an_admin_may_sign_off_a_completed_job_the_tenant_never_confirmed(): void
    {
        $this->move($this->w->managerA, $this->workOrder('completed'), 'verified')->assertStatus(422);
        $this->move($this->w->admin, $this->workOrder('completed'), 'verified')->assertOk();
    }

    public function test_even_an_admin_cannot_verify_work_that_is_not_complete(): void
    {
        foreach (['open', 'assigned', 'in-progress'] as $from) {
            $m = $this->workOrder($from);
            $this->move($this->w->admin, $m, 'verified')->assertStatus(422, "admin {$from} → verified");
            $this->assertSame($from, $m->fresh()->status);
        }
    }

    public function test_a_tenant_cannot_move_a_work_order(): void
    {
        $m = $this->workOrder('completed');

        $this->move($this->w->tenantUser, $m, 'closed')->assertForbidden();
        $this->assertSame('completed', $m->fresh()->status);
    }

    // ======================= Leases ============================================

    private function lease(array $over = []): array
    {
        return $over + [
            'unitId' => 'unit_a', 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada',
            'startDate' => '2027-01-01', 'endDate' => '2027-12-31', 'rent' => 1_200_000, 'billingCycle' => 'annual',
        ];
    }

    public function test_a_lease_can_only_be_created_active(): void
    {
        $this->as($this->w->managerA);

        foreach (['terminated', 'expired', 'renewed', 'expiring'] as $status) {
            $this->postJson('/api/v1/leases', $this->lease(['status' => $status]))->assertStatus(422);
        }
        $this->postJson('/api/v1/leases', $this->lease(['unitId' => 'unit_free', 'status' => 'active']))->assertCreated()->assertJsonPath('data.status', 'active');
        $this->postJson('/api/v1/leases', $this->lease(['unitId' => 'unit_free2']))->assertCreated()->assertJsonPath('data.status', 'active');
    }

    public function test_a_lease_status_cannot_be_changed_through_a_plain_edit(): void
    {
        $this->w->uA->forceFill(['status' => 'occupied'])->save();
        $this->as($this->w->managerA);

        foreach (['terminated', 'expired', 'renewed'] as $status) {
            $this->patchJson('/api/v1/leases/lease_a', ['status' => $status])->assertStatus(422);
        }
        $this->assertSame('active', $this->w->lease->fresh()->status);
        $this->assertSame('occupied', $this->w->uA->fresh()->status);
    }

    public function test_other_lease_fields_can_still_be_edited_including_restating_the_status(): void
    {
        $this->as($this->w->managerA)->patchJson('/api/v1/leases/lease_a', ['status' => 'active', 'rent' => 1_500_000, 'notes' => 'Rent review'])
            ->assertOk()->assertJsonPath('data.rent', 1_500_000);

        $this->assertSame('active', Lease::query()->find('lease_a')->status);
    }

    // ======================= Invoices ==========================================

    private function invoiceUpdate(array $body, ?Invoice $invoice = null)
    {
        $invoice ??= $this->w->invoice(['dueDate' => '2026-12-01']);

        return $this->as($this->w->managerA)->patchJson("/api/v1/invoices/{$invoice->id}", $body);
    }

    public function test_status_and_money_fields_are_ignored_on_an_invoice_edit(): void
    {
        $invoice = $this->w->invoice(['dueDate' => '2026-12-01']);

        $this->invoiceUpdate(['status' => 'fully-paid', 'amountPaid' => 100_000, 'total' => 1], $invoice)->assertOk();

        $fresh = $invoice->fresh();
        $this->assertSame('unpaid', $fresh->status);
        $this->assertSame(0, (int) $fresh->amountPaid);
        $this->assertSame(100_000, (int) $fresh->total);
    }

    public function test_editing_line_items_recomputes_the_total_and_the_status(): void
    {
        $invoice = $this->w->invoice(['dueDate' => '2026-12-01', 'amountPaid' => 40_000, 'status' => 'partially-paid']);

        $this->invoiceUpdate(['lineItems' => [['description' => 'Rent', 'type' => 'rent', 'amount' => 30_000]]], $invoice)
            ->assertOk()->assertJsonPath('data.total', 30_000)->assertJsonPath('data.status', 'fully-paid');

        $this->invoiceUpdate(['lineItems' => [['description' => 'Rent', 'type' => 'rent', 'amount' => 90_000], ['description' => 'Fee', 'type' => 'service', 'amount' => 10_000]]], $invoice)
            ->assertOk()->assertJsonPath('data.total', 100_000)->assertJsonPath('data.status', 'partially-paid');

        $this->assertNotEmpty($invoice->fresh()->lineItems[0]['id']);
    }

    public function test_moving_the_due_date_updates_overdue_status(): void
    {
        $invoice = $this->w->invoice(['dueDate' => '2026-12-01', 'status' => 'unpaid']);

        $this->invoiceUpdate(['dueDate' => '2026-11-01'], $invoice)->assertOk()->assertJsonPath('data.status', 'overdue');
        $this->invoiceUpdate(['dueDate' => '2026-12-31'], $invoice)->assertOk()->assertJsonPath('data.status', 'unpaid');
    }

    public function test_a_cancelled_invoice_cannot_be_edited_and_an_invoice_cannot_be_repointed(): void
    {
        $cancelled = $this->w->invoice(['status' => 'cancelled']);
        $this->invoiceUpdate(['dueDate' => '2027-01-01'], $cancelled)->assertStatus(422);

        $invoice = $this->w->invoice(['dueDate' => '2026-12-01']);
        $this->invoiceUpdate(['leaseId' => 'lease_b'], $invoice)->assertStatus(422);
        $this->invoiceUpdate(['unitId' => 'unit_b'], $invoice)->assertStatus(422);
        $this->invoiceUpdate(['tenantId' => 'ten_bee'], $invoice)->assertStatus(422);

        $this->assertSame('lease_a', $invoice->fresh()->leaseId);
    }
}

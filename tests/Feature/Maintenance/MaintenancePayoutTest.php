<?php

namespace Tests\Feature\Maintenance;

use App\Mail\MaintenancePayoutMail;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\MaintenancePayout;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\PropertyMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\PmsTestCase;
use Tests\Support\World;

/** Technician payouts: request → approve → paid (or rejected), with a linked expense and a stable bank snapshot. */
class MaintenancePayoutTest extends PmsTestCase
{
    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tech = World::user('maintenance', 'tech@pms.test');
        World::member($this->w->pA, $this->tech, 'maintenance')->update([
            'bankName' => 'GTBank', 'bankAccountName' => 'Tunde Tech', 'bankAccountNumber' => '0123456789', 'bankCode' => '058',
        ]);
    }

    private function workOrder(string $status = 'tenant-confirmed', array $overrides = []): MaintenanceRequest
    {
        return MaintenanceRequest::query()->create($overrides + [
            'id' => 'mnt_'.uniqid(), 'code' => 'MNT-'.strtoupper(substr(uniqid(), -5)),
            'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'tenantId' => 'ten_ada',
            'title' => 'Leaking tap', 'category' => 'plumbing', 'urgency' => 'medium', 'status' => $status,
            'createdAt' => now()->toIso8601String(), 'assignedToId' => $this->tech->id,
            'materialsCost' => 12_000, 'labourCost' => 8_000,
        ]);
    }

    private function request(?MaintenanceRequest $m = null, array $body = [], ?User $as = null)
    {
        $m ??= $this->workOrder();

        return $this->as($as ?? $this->tech)->postJson("/api/v1/maintenance/{$m->id}/payout", $body);
    }

    private function requested(array $body = []): MaintenancePayout
    {
        return MaintenancePayout::query()->findOrFail($this->request(null, $body)->assertCreated()->json('data.id'));
    }

    private function approved(): MaintenancePayout
    {
        $p = $this->requested();
        $this->as($this->w->ownerA)->postJson("/api/v1/maintenance-payouts/{$p->id}/approve")->assertOk();

        return $p->refresh();
    }

    // ---- Requesting ---------------------------------------------------------

    public function test_the_assigned_technician_requests_what_the_job_cost_with_a_bank_snapshot(): void
    {
        $res = $this->request()->assertCreated();

        $res->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.amount', 20_000)
            ->assertJsonPath('data.materialsAmount', 12_000)
            ->assertJsonPath('data.labourAmount', 8_000)
            ->assertJsonPath('data.staffId', $this->tech->id)
            ->assertJsonPath('data.bankName', 'GTBank')
            ->assertJsonPath('data.bankAccountNumber', '0123456789')
            ->assertJsonPath('data.bankCode', '058');
    }

    public function test_the_technician_can_ask_for_a_different_amount_and_add_a_note(): void
    {
        $this->request(null, ['amount' => 35_000, 'note' => 'Needed extra pipe'])->assertCreated()
            ->assertJsonPath('data.amount', 35_000)->assertJsonPath('data.note', 'Needed extra pipe');
    }

    public function test_a_zero_or_negative_amount_is_refused(): void
    {
        $free = $this->workOrder('tenant-confirmed', ['materialsCost' => 0, 'labourCost' => 0]);

        $this->request($free)->assertStatus(422);
        $this->request($this->workOrder(), ['amount' => 0])->assertStatus(422);
        $this->request($this->workOrder(), ['amount' => -5])->assertStatus(422);
        $this->assertSame(0, MaintenancePayout::query()->count());
    }

    public function test_payment_can_only_be_requested_once_the_tenant_has_confirmed(): void
    {
        foreach (['reported', 'assigned', 'in-progress', 'completed'] as $status) {
            $this->request($this->workOrder($status))->assertStatus(422);
        }
        foreach (['tenant-confirmed', 'verified', 'closed'] as $status) {
            $this->request($this->workOrder($status))->assertCreated();
        }
    }

    public function test_only_the_assigned_technician_or_an_admin_can_request(): void
    {
        $m = $this->workOrder();
        $colleague = World::user('maintenance', 'colleague@pms.test');
        World::member($this->w->pA, $colleague, 'maintenance');

        foreach (['colleague' => $colleague, 'manager' => $this->w->managerA, 'owner' => $this->w->ownerA, 'tenant' => $this->w->tenantUser, 'finance' => $this->w->financeA] as $who => $user) {
            $this->request($m, [], $user)->assertForbidden();
        }
        $this->assertSame(0, MaintenancePayout::query()->count());

        $this->request($m, [], $this->w->admin)->assertCreated();
    }

    public function test_a_technician_cannot_request_on_a_property_they_do_not_belong_to(): void
    {
        $other = $this->workOrder('tenant-confirmed', ['propertyId' => 'prop_b', 'unitId' => 'unit_b']);

        $this->request($other)->assertForbidden();
    }

    public function test_only_one_open_request_per_work_order_even_after_it_is_paid(): void
    {
        $m = $this->workOrder();
        $first = $this->request($m)->assertCreated()->json('data.id');
        $this->request($m)->assertStatus(409);

        $this->as($this->w->ownerA)->postJson("/api/v1/maintenance-payouts/{$first}/approve")->assertOk();
        $this->postJson("/api/v1/maintenance-payouts/{$first}/pay")->assertOk();

        $this->request($m)->assertStatus(409);
    }

    public function test_a_rejected_request_can_be_resubmitted(): void
    {
        $m = $this->workOrder();
        $first = $this->request($m)->assertCreated()->json('data.id');
        $this->as($this->w->ownerA)->postJson("/api/v1/maintenance-payouts/{$first}/reject", ['reason' => 'Receipt missing'])->assertOk();

        $this->request($m, ['amount' => 18_000])->assertCreated();
    }

    public function test_the_bank_details_are_a_snapshot_that_later_edits_do_not_change(): void
    {
        $payout = $this->requested();
        PropertyMember::query()->where('userId', $this->tech->id)->update(['bankAccountNumber' => '9999999999']);

        $this->assertSame('0123456789', $payout->fresh()->bankAccountNumber);
    }

    public function test_the_request_notifies_the_payers_of_that_property_only(): void
    {
        $this->request()->assertCreated();

        foreach (['ownerA', 'managerA', 'financeA'] as $who) {
            $this->assertDatabaseHas('notifications', ['forUserId' => $this->w->{$who}->id, 'title' => 'Payment requested']);
        }
        $this->assertDatabaseMissing('notifications', ['forUserId' => $this->w->ownerB->id, 'title' => 'Payment requested']);
        $this->assertDatabaseMissing('notifications', ['forUserId' => $this->tech->id, 'title' => 'Payment requested']);
        Mail::assertQueued(MaintenancePayoutMail::class, fn ($m) => $m->hasTo($this->w->ownerA->email) && $m->hasTo($this->w->managerA->email));
    }

    public function test_finance_staff_of_other_properties_are_not_told_about_this_payout(): void
    {
        $outsider = World::user('finance', 'finance.b@pms.test');
        World::member($this->w->pB, $outsider, 'finance');

        $this->request()->assertCreated();

        $this->assertDatabaseMissing('notifications', ['forUserId' => $outsider->id, 'title' => 'Payment requested']);
        Mail::assertNotQueued(MaintenancePayoutMail::class, fn ($m) => $m->hasTo($outsider->email));
    }

    // ---- Approving & rejecting ----------------------------------------------

    public function test_owner_manager_finance_and_admin_of_the_property_can_approve(): void
    {
        foreach (['ownerA', 'managerA', 'financeA', 'admin'] as $who) {
            $payout = $this->requested();
            $this->as($this->w->{$who})->postJson("/api/v1/maintenance-payouts/{$payout->id}/approve")
                ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approvedById', $this->w->{$who}->id);
        }
    }

    public function test_nobody_else_can_approve_reject_or_pay(): void
    {
        $payout = $this->approved();
        $requested = $this->requested();
        $agent = World::user('agent', 'agent@pms.test');
        World::member($this->w->pA, $agent, 'agent');

        foreach ([$this->tech, $this->w->tenantUser, $this->w->stranger, $agent, $this->w->ownerB] as $user) {
            $this->as($user);
            $this->postJson("/api/v1/maintenance-payouts/{$requested->id}/approve")->assertForbidden();
            $this->postJson("/api/v1/maintenance-payouts/{$requested->id}/reject", ['reason' => 'nope nope'])->assertForbidden();
            $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/pay")->assertForbidden();
        }

        $this->assertSame('requested', $requested->fresh()->status);
        $this->assertSame('approved', $payout->fresh()->status);
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_finance_staff_of_another_property_cannot_act_on_this_payout(): void
    {
        $outsider = World::user('finance', 'finance.b@pms.test');
        World::member($this->w->pB, $outsider, 'finance');
        $payout = $this->requested();

        $this->as($outsider);
        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/reject", ['reason' => 'not mine'])->assertForbidden();

        $this->assertSame('requested', $payout->fresh()->status);
    }

    public function test_the_steps_must_happen_in_order(): void
    {
        $payout = $this->requested();
        $this->as($this->w->ownerA);

        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/pay")->assertStatus(422);      // not approved yet
        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/approve")->assertOk();
        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/approve")->assertStatus(422);   // already approved
        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/pay")->assertOk();
        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/pay")->assertStatus(422);       // already paid
        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/reject", ['reason' => 'too late'])->assertStatus(422);
    }

    public function test_rejection_needs_a_reason_and_can_follow_a_request_or_an_approval(): void
    {
        $a = $this->requested();
        $this->as($this->w->ownerA);
        $this->postJson("/api/v1/maintenance-payouts/{$a->id}/reject", [])->assertStatus(422);
        $this->postJson("/api/v1/maintenance-payouts/{$a->id}/reject", ['reason' => 'ab'])->assertStatus(422);
        $this->postJson("/api/v1/maintenance-payouts/{$a->id}/reject", ['reason' => 'Overpriced'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejectedReason', 'Overpriced');

        $b = $this->approved();
        $this->as($this->w->ownerA)->postJson("/api/v1/maintenance-payouts/{$b->id}/reject", ['reason' => 'Changed our mind'])->assertOk();
    }

    public function test_a_rejected_payout_cannot_be_approved_or_paid(): void
    {
        $payout = $this->requested();
        $this->as($this->w->ownerA)->postJson("/api/v1/maintenance-payouts/{$payout->id}/reject", ['reason' => 'Overpriced'])->assertOk();

        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/approve")->assertStatus(422);
        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/pay")->assertStatus(422);
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_the_technician_is_told_at_each_step(): void
    {
        $payout = $this->requested();
        $this->as($this->w->ownerA);
        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/approve");
        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/pay");

        $titles = Notification::query()->where('forUserId', $this->tech->id)->pluck('title')->all();
        $this->assertContains('Payout approved', $titles);
        $this->assertContains("You've been paid", $titles);
        Mail::assertQueued(MaintenancePayoutMail::class, fn ($m) => $m->hasTo($this->tech->email));
    }

    // ---- Paying -------------------------------------------------------------

    public function test_paying_records_the_payout_and_a_matching_expense_exactly_once(): void
    {
        $payout = $this->approved();

        $this->as($this->w->managerA)->postJson("/api/v1/maintenance-payouts/{$payout->id}/pay", ['method' => 'cash', 'reference' => 'RCPT-77'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paymentMethod', 'cash')
            ->assertJsonPath('data.paymentReference', 'RCPT-77')
            ->assertJsonPath('data.paidById', $this->w->managerA->id);

        $expense = Expense::query()->sole();
        $this->assertSame($expense->id, $payout->fresh()->expenseId);
        $this->assertSame(20_000, (int) $expense->amount);
        $this->assertSame('prop_a', $expense->propertyId);
        $this->assertSame('repairs', $expense->category);
        $this->assertSame('paid', $expense->status);
        $this->assertStringContainsString($payout->code, $expense->description);

        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/pay")->assertStatus(422);
        $this->assertSame(1, Expense::query()->count(), 'a second pay must not create a second expense');
    }

    public function test_a_payment_that_loses_the_race_is_refused_and_creates_no_second_expense(): void
    {
        $payout = $this->approved();
        $id = $payout->id;

        // Simulate a concurrent request: right after this request first reads the
        // payout (the authorisation lookup), a rival request pays it.
        $raced = false;
        MaintenancePayout::retrieved(function (MaintenancePayout $p) use (&$raced, $id) {
            if (! $raced && $p->id === $id) {
                $raced = true;
                DB::table('maintenance_payouts')->where('id', $id)->update(['status' => 'paid']);
            }
        });

        $this->as($this->w->managerA)->postJson("/api/v1/maintenance-payouts/{$id}/pay")->assertStatus(422);

        $this->assertTrue($raced);
        $this->assertSame(0, Expense::query()->count(), 'the loser must not record a payment');
    }

    public function test_the_payment_method_defaults_to_bank_transfer_and_unknown_methods_are_refused(): void
    {
        $payout = $this->approved();
        $this->as($this->w->ownerA)->postJson("/api/v1/maintenance-payouts/{$payout->id}/pay", ['method' => 'bitcoin'])->assertStatus(422);
        // Only methods that really happen are accepted: nothing here sends money through Paystack.
        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/pay", ['method' => 'paystack-transfer'])->assertStatus(422);
        $this->assertSame('approved', $payout->fresh()->status);

        $this->postJson("/api/v1/maintenance-payouts/{$payout->id}/pay")->assertOk()->assertJsonPath('data.paymentMethod', 'bank-transfer');
    }

    public function test_the_actions_leave_an_audit_trail(): void
    {
        $payout = $this->approved();
        $this->as($this->w->ownerA)->postJson("/api/v1/maintenance-payouts/{$payout->id}/pay");

        $summaries = AuditLog::query()->where('entityId', $payout->id)->pluck('summary')->implode(' | ');
        $this->assertStringContainsString('Requested', $summaries);
        $this->assertStringContainsString('Approved', $summaries);
        $this->assertStringContainsString('Paid', $summaries);
    }

    // ---- Who sees what -------------------------------------------------------

    private function listedIds(User $user): array
    {
        return collect($this->as($user)->getJson('/api/v1/maintenance-payouts')->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_the_list_is_scoped_to_the_callers_properties(): void
    {
        $payout = $this->requested();

        foreach (['ownerA', 'managerA', 'financeA', 'admin'] as $who) {
            $this->assertSame([$payout->id], $this->listedIds($this->w->{$who}), $who);
        }
        $this->assertSame([$payout->id], $this->listedIds($this->tech));
        foreach (['ownerB', 'tenantUser', 'stranger'] as $who) {
            $this->assertSame([], $this->listedIds($this->w->{$who}), $who);
        }
    }

    public function test_a_technician_does_not_see_a_colleagues_payouts_or_bank_details(): void
    {
        $colleague = World::user('maintenance', 'colleague@pms.test');
        World::member($this->w->pA, $colleague, 'maintenance')->update(['bankAccountNumber' => '5555555555', 'bankName' => 'Zenith']);
        $theirs = $this->request($this->workOrder('tenant-confirmed', ['assignedToId' => $colleague->id]), [], $colleague)->assertCreated()->json('data.id');
        $mine = $this->requested();

        $seen = $this->listedIds($this->tech);

        $this->assertSame([$mine->id], $seen);
        $this->assertNotContains($theirs, $seen);
    }

    public function test_it_needs_a_login(): void
    {
        $this->getJson('/api/v1/maintenance-payouts')->assertUnauthorized();
        $this->postJson('/api/v1/maintenance/x/payout')->assertUnauthorized();
        $this->postJson('/api/v1/maintenance-payouts/x/approve')->assertUnauthorized();
    }

    public function test_unknown_ids_are_404(): void
    {
        $this->as($this->w->ownerA)->postJson('/api/v1/maintenance-payouts/nope/approve')->assertNotFound();
        $this->as($this->tech)->postJson('/api/v1/maintenance/nope/payout')->assertNotFound();
    }
}

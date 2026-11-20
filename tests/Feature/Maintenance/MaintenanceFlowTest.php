<?php

namespace Tests\Feature\Maintenance;

use App\Mail\MaintenanceRequestedMail;
use App\Mail\MaintenanceUpdateMail;
use App\Models\AuditLog;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\PmsTestCase;
use Tests\Support\World;

/** A work order from report to close: who is told what, what is recorded, who may take each step. */
class MaintenanceFlowTest extends PmsTestCase
{
    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');

        $this->tech = World::user('maintenance', 'tech.a@pms.test');
        World::member($this->w->pA, $this->tech, 'maintenance');
    }

    private function job(array $over = []): array
    {
        return $over + ['propertyId' => 'prop_a', 'unitId' => 'unit_a', 'tenantId' => 'ten_ada', 'title' => 'Leaking tap', 'category' => 'plumbing', 'urgency' => 'high'];
    }

    private function workOrder(string $status = 'open', array $over = []): MaintenanceRequest
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

    private function mailedTo(string $email, ?string $contains = null): bool
    {
        return Mail::queued(MaintenanceUpdateMail::class, fn ($m) => $m->hasTo($email) && ($contains === null || str_contains($m->build()->subject, $contains)))->isNotEmpty();
    }

    // ---- Reporting ----------------------------------------------------------

    public function test_a_tenant_reports_a_problem_and_the_property_team_is_told(): void
    {
        $id = $this->as($this->w->tenantUser)->postJson('/api/v1/maintenance', $this->job())->assertCreated()->json('data.id');

        $m = MaintenanceRequest::query()->findOrFail($id);
        $this->assertSame('open', $m->status);
        $this->assertNotEmpty($m->code);
        $this->assertNotNull($m->createdAt);

        $this->assertTrue(Notification::query()->where('forRole', 'manager')->where('title', 'New maintenance request')->where('body', 'like', "{$m->code}:%")->exists());
        $this->assertTrue(AuditLog::query()->where('entityId', $id)->where('action', 'create')->exists());

        Mail::assertQueued(MaintenanceRequestedMail::class, fn ($mail) => $mail->hasTo($this->w->ownerA->email) && $mail->hasTo($this->w->managerA->email));
    }

    public function test_the_request_email_goes_to_that_propertys_team_only(): void
    {
        $this->as($this->w->tenantUser)->postJson('/api/v1/maintenance', $this->job())->assertCreated();

        Mail::assertQueued(MaintenanceRequestedMail::class, fn ($mail) => ! $mail->hasTo($this->w->ownerB->email) && ! $mail->hasTo($this->tech->email) && ! $mail->hasTo($this->w->tenantUser->email));
    }

    public function test_a_tenant_cannot_report_a_problem_on_another_property_or_for_another_tenant(): void
    {
        $this->as($this->w->tenantUser);

        $this->postJson('/api/v1/maintenance', $this->job(['propertyId' => 'prop_b', 'unitId' => 'unit_b']))->assertForbidden();
        $this->postJson('/api/v1/maintenance', $this->job(['tenantId' => 'ten_bee']))->assertStatus(422);
    }

    public function test_the_report_needs_the_basics(): void
    {
        $this->as($this->w->tenantUser);

        foreach (['title', 'category', 'urgency', 'unitId'] as $field) {
            $payload = $this->job();
            unset($payload[$field]);
            $this->postJson('/api/v1/maintenance', $payload)->assertStatus(422);
        }
        $this->postJson('/api/v1/maintenance', $this->job(['urgency' => 'whenever']))->assertStatus(422);
    }

    // ---- Moving it along: stamps, audit, emails ------------------------------

    public function test_each_step_stamps_the_time_or_person_and_is_audited_once(): void
    {
        $m = $this->workOrder('in-progress');

        $this->move($this->tech, $m, 'completed')->assertOk();
        $this->assertNotNull($m->fresh()->completedAt);

        $m->forceFill(['status' => 'tenant-confirmed'])->save();
        $this->move($this->w->managerA, $m, 'verified')->assertOk();
        $this->assertSame($this->w->managerA->id, $m->fresh()->verifiedById);

        $this->move($this->w->managerA, $m, 'closed')->assertOk();
        $this->assertNotNull($m->fresh()->closedAt);

        foreach (['completed', 'verified', 'closed'] as $status) {
            $this->assertSame(1, AuditLog::query()->where('entityId', $m->id)->where('summary', 'like', "%status → {$status}")->count(), $status);
        }
    }

    public function test_a_save_that_does_not_change_the_status_is_neither_audited_nor_emailed(): void
    {
        $m = $this->workOrder('in-progress');

        $this->move($this->tech, $m, 'in-progress', ['labourCost' => 2_500])->assertOk();

        $this->assertSame(0, AuditLog::query()->where('entityId', $m->id)->count());
        Mail::assertNothingQueued();
        $this->assertSame(2_500, (int) $m->fresh()->labourCost);
    }

    public function test_assigning_tells_the_technician_once(): void
    {
        $m = $this->workOrder('open', ['assignedToId' => null]);

        $this->move($this->w->managerA, $m, 'assigned', ['assignedToId' => $this->tech->id])->assertOk();

        $this->assertTrue($this->mailedTo($this->tech->email, 'assigned'));
        $this->assertSame(1, Mail::queued(MaintenanceUpdateMail::class)->count(), 'one email, not one for the assignee change and another for the status');
    }

    public function test_starting_and_finishing_work_tell_the_tenant(): void
    {
        $m = $this->workOrder('assigned');

        $this->move($this->tech, $m, 'in-progress')->assertOk();
        $this->assertTrue($this->mailedTo($this->w->tenantUser->email, 'Work has started'));

        $this->move($this->tech, $m, 'completed')->assertOk();
        $this->assertTrue($this->mailedTo($this->w->tenantUser->email, 'Please confirm'));
    }

    public function test_verifying_tells_the_technician_and_tenant_and_closing_tells_the_tenant(): void
    {
        $m = $this->workOrder('tenant-confirmed');

        $this->move($this->w->managerA, $m, 'verified')->assertOk();
        $this->assertTrue($this->mailedTo($this->tech->email, 'verified'));
        $this->assertTrue($this->mailedTo($this->w->tenantUser->email, 'verified'));

        Mail::fake();
        $this->move($this->w->managerA, $m, 'closed')->assertOk();
        $this->assertTrue($this->mailedTo($this->w->tenantUser->email, 'closed'));
        $this->assertFalse($this->mailedTo($this->tech->email), 'closing is for the tenant');
    }

    public function test_the_technicians_own_costs_are_recorded_with_completion(): void
    {
        $m = $this->workOrder('in-progress');

        $this->move($this->tech, $m, 'completed', ['materialsCost' => 8_000, 'labourCost' => 4_000, 'materialsMatch' => true, 'actualMaterials' => '2 taps'])->assertOk();

        $fresh = $m->fresh();
        $this->assertSame([8_000, 4_000], [(int) $fresh->materialsCost, (int) $fresh->labourCost]);
        $this->assertTrue((bool) $fresh->materialsMatch);
        $this->assertSame('2 taps', $fresh->actualMaterials);

        $this->move($this->tech, $this->workOrder('in-progress'), 'completed', ['labourCost' => -1])->assertStatus(422);
    }

    // ---- Inspection rounds ---------------------------------------------------

    private function round(array $over = []): array
    {
        return $over + ['stage' => 'triage', 'date' => '2026-11-14', 'outcome' => 'issues', 'findings' => 'Corroded pipe under the sink.'];
    }

    public function test_a_round_is_appended_attributed_audited_and_announced(): void
    {
        $m = $this->workOrder('assigned');

        $this->as($this->tech)->postJson("/api/v1/maintenance/{$m->id}/rounds", $this->round())->assertCreated();
        $this->as($this->w->managerA)->postJson("/api/v1/maintenance/{$m->id}/rounds", $this->round(['stage' => 'final', 'outcome' => 'ok', 'findings' => 'All good.']))->assertCreated();

        $rounds = $m->fresh()->rounds;
        $this->assertCount(2, $rounds);
        $this->assertSame(['triage', 'final'], array_column($rounds, 'stage'), 'in the order recorded');
        $this->assertSame([$this->tech->id, $this->w->managerA->id], array_column($rounds, 'byId'));
        $this->assertCount(2, array_unique(array_column($rounds, 'id')));

        $this->assertTrue(Notification::query()->where('forRole', 'manager')->where('title', 'Triage round recorded')->where('body', 'like', '%issues flagged%')->exists());
        $this->assertTrue(Notification::query()->where('title', 'Post-repair round recorded')->where('body', 'like', '%no issues found%')->exists());
        $this->assertSame(2, AuditLog::query()->where('entityId', $m->id)->where('summary', 'like', '%round recorded%')->count());
    }

    public function test_a_round_cannot_be_credited_to_someone_else(): void
    {
        $m = $this->workOrder('assigned');

        $this->as($this->tech)->postJson("/api/v1/maintenance/{$m->id}/rounds", $this->round(['byId' => $this->w->ownerA->id]))->assertCreated();

        $this->assertSame($this->tech->id, $m->fresh()->rounds[0]['byId']);
    }

    public function test_a_round_needs_a_valid_stage_date_outcome_and_findings(): void
    {
        $m = $this->workOrder('assigned');
        $this->as($this->tech);

        foreach (['stage', 'date', 'outcome', 'findings'] as $field) {
            $payload = $this->round();
            unset($payload[$field]);
            $this->postJson("/api/v1/maintenance/{$m->id}/rounds", $payload)->assertStatus(422);
        }
        $this->postJson("/api/v1/maintenance/{$m->id}/rounds", $this->round(['stage' => 'midway']))->assertStatus(422);
        $this->postJson("/api/v1/maintenance/{$m->id}/rounds", $this->round(['outcome' => 'maybe']))->assertStatus(422);

        $this->assertEmpty($m->fresh()->rounds);
    }

    public function test_only_people_who_run_the_job_can_log_a_round(): void
    {
        $m = $this->workOrder('assigned');

        foreach (['ownerA', 'managerA', 'admin'] as $who) {
            $this->as($this->w->{$who})->postJson("/api/v1/maintenance/{$m->id}/rounds", $this->round())->assertCreated();
        }
        foreach (['tenantUser', 'stranger', 'ownerB'] as $who) {
            $this->as($this->w->{$who})->postJson("/api/v1/maintenance/{$m->id}/rounds", $this->round())->assertForbidden();
        }
        $this->assertCount(3, $m->fresh()->rounds);
    }

    // ---- The tenant's confirmation --------------------------------------------

    public function test_the_tenant_confirms_and_the_team_and_technician_are_told(): void
    {
        $m = $this->workOrder('completed');

        $this->as($this->w->tenantUser)->postJson("/api/v1/maintenance/{$m->id}/confirm")
            ->assertOk()->assertJsonPath('data.status', 'tenant-confirmed');

        $fresh = $m->fresh();
        $this->assertNotNull($fresh->tenantConfirmedAt);
        $this->assertSame($this->w->tenantUser->id, $fresh->tenantConfirmedById);
        $this->assertTrue(Notification::query()->where('forRole', 'manager')->where('title', 'Tenant confirmed the work')->exists());
        $this->assertTrue(Notification::query()->where('forUserId', $this->tech->id)->where('title', 'like', 'Work confirmed%')->exists());
        $this->assertTrue($this->mailedTo($this->w->managerA->email, 'confirmed'));
        $this->assertTrue($this->mailedTo($this->tech->email, 'confirmed'));
    }

    public function test_only_the_requests_own_tenant_can_confirm_and_only_once_complete(): void
    {
        $m = $this->workOrder('completed');
        $other = World::user('tenant', 'bee@tenant.test');
        World::member($this->w->pB, $other, 'tenant', 'ten_bee');

        foreach ([$this->tech, $this->w->managerA, $this->w->ownerA, $other, $this->w->stranger] as $who) {
            $this->as($who)->postJson("/api/v1/maintenance/{$m->id}/confirm")->assertStatus(403);
        }
        $this->assertSame('completed', $m->fresh()->status);

        foreach (['open', 'assigned', 'in-progress'] as $status) {
            $early = $this->workOrder($status);
            $this->as($this->w->tenantUser)->postJson("/api/v1/maintenance/{$early->id}/confirm")->assertStatus(422);
        }
    }

    public function test_confirming_twice_is_refused(): void
    {
        $m = $this->workOrder('completed');
        $this->as($this->w->tenantUser)->postJson("/api/v1/maintenance/{$m->id}/confirm")->assertOk();

        $this->postJson("/api/v1/maintenance/{$m->id}/confirm")->assertStatus(422);
    }

    // ---- Seeing the work -------------------------------------------------------

    public function test_the_list_is_scoped_by_property_and_a_tenant_sees_only_their_own_requests(): void
    {
        $mine = $this->workOrder('open');
        $b = $this->workOrder('open', ['propertyId' => 'prop_b', 'unitId' => 'unit_b', 'tenantId' => 'ten_bee']);
        $other = $this->workOrder('open', ['tenantId' => 'ten_other_in_a']);

        $ids = fn () => collect($this->getJson('/api/v1/maintenance?per_page=100')->assertOk()->json('data'))->pluck('id')->all();

        $this->as($this->w->managerA);
        $this->assertEqualsCanonicalizing([$mine->id, $other->id], $ids());

        $this->as($this->tech);
        $this->assertEqualsCanonicalizing([$mine->id, $other->id], $ids(), 'a technician sees the property’s requests, not B’s');

        $this->as($this->w->ownerB);
        $this->assertSame([$b->id], $ids());

        $this->as($this->w->tenantUser);
        $this->assertSame([$mine->id], $ids());
    }

    public function test_a_technician_cannot_work_on_another_propertys_order(): void
    {
        $b = $this->workOrder('assigned', ['propertyId' => 'prop_b', 'unitId' => 'unit_b', 'tenantId' => 'ten_bee']);

        $this->move($this->tech, $b, 'in-progress')->assertStatus(403);
        $this->as($this->tech)->postJson("/api/v1/maintenance/{$b->id}/rounds", $this->round())->assertForbidden();
        $this->assertSame('assigned', $b->fresh()->status);
    }
}

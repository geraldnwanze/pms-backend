<?php

namespace Tests\Feature\Operations;

use App\Models\Notice;
use App\Models\TaskItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;
use Tests\Support\World;

/** To-dos (who sees and ticks them) and notices (who they are for, and which property they belong to). */
class TaskAndNoticeTest extends PmsTestCase
{
    private User $tech;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');

        $this->tech = World::user('maintenance', 'tech.a@pms.test');
        $this->agent = World::user('agent', 'agent.a@pms.test');
        World::member($this->w->pA, $this->tech, 'maintenance');
        World::member($this->w->pA, $this->agent, 'agent');
    }

    // ======================= Tasks ============================================

    private function task(string $id, ?string $property, ?string $assignee, array $over = []): TaskItem
    {
        return TaskItem::query()->create($over + [
            'id' => $id, 'title' => "Task {$id}", 'dueDate' => '2026-11-20', 'done' => false, 'priority' => 'medium',
            'propertyId' => $property, 'assignedToId' => $assignee,
        ]);
    }

    private function taskIds(User $as, string $query = ''): array
    {
        return collect($this->as($as)->getJson('/api/v1/tasks?per_page=100'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_a_task_starts_open_with_medium_priority(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/tasks', ['title' => 'Chase invoices', 'dueDate' => '2026-11-30', 'priority' => 'medium'])
            ->assertCreated()->assertJsonPath('data.done', false)->assertJsonPath('data.priority', 'medium');
    }

    public function test_a_task_needs_a_title_a_due_date_and_a_known_priority(): void
    {
        $ok = ['title' => 'Do it', 'dueDate' => '2026-11-30', 'priority' => 'high'];
        $this->as($this->w->managerA);

        foreach (['title', 'dueDate', 'priority'] as $field) {
            $payload = $ok;
            unset($payload[$field]);
            $this->postJson('/api/v1/tasks', $payload)->assertStatus(422);
        }
        $this->postJson('/api/v1/tasks', ['priority' => 'urgent'] + $ok)->assertStatus(422);
        $this->postJson('/api/v1/tasks', ['dueDate' => 'tomorrow'] + $ok)->assertStatus(422);
    }

    public function test_ticking_a_task_flips_it_and_ticking_again_undoes_it(): void
    {
        $task = $this->task('t1', 'prop_a', $this->tech->id);

        $this->as($this->tech)->postJson("/api/v1/tasks/{$task->id}/toggle")->assertOk()->assertJsonPath('data.done', true);
        $this->postJson("/api/v1/tasks/{$task->id}/toggle")->assertOk()->assertJsonPath('data.done', false);
    }

    public function test_people_see_the_tasks_assigned_to_them_and_managers_also_see_their_propertys(): void
    {
        $mine = $this->task('t_mine', 'prop_a', $this->tech->id);
        $propertyWide = $this->task('t_team', 'prop_a', $this->agent->id);
        $otherPortfolio = $this->task('t_b', 'prop_b', $this->w->ownerB->id);
        $loose = $this->task('t_loose', null, $this->agent->id);

        $this->assertSame([$mine->id], $this->taskIds($this->tech));
        $this->assertEqualsCanonicalizing([$propertyWide->id, $loose->id], $this->taskIds($this->agent));
        $this->assertEqualsCanonicalizing([$mine->id, $propertyWide->id], $this->taskIds($this->w->managerA), 'the manager’s own property, not another owner’s, and not loose tasks of other people');
        $this->assertEqualsCanonicalizing([$mine->id, $propertyWide->id, $otherPortfolio->id, $loose->id], $this->taskIds($this->w->admin));
    }

    public function test_a_manager_cannot_open_or_tick_another_portfolios_task(): void
    {
        $theirs = $this->task('t_b', 'prop_b', $this->w->ownerB->id);

        $this->as($this->w->managerA);
        $this->assertContains($this->getJson("/api/v1/tasks/{$theirs->id}")->status(), [403, 404]);
        $this->assertContains($this->postJson("/api/v1/tasks/{$theirs->id}/toggle")->status(), [403, 404]);
        $this->assertFalse((bool) $theirs->fresh()->done);
    }

    public function test_a_technician_cannot_tick_a_colleagues_task(): void
    {
        $colleague = World::user('maintenance', 'tech.two@pms.test');
        World::member($this->w->pA, $colleague, 'maintenance');
        $theirs = $this->task('t_other', 'prop_a', $colleague->id);

        $this->assertContains($this->as($this->tech)->postJson("/api/v1/tasks/{$theirs->id}/toggle")->status(), [403, 404]);
        $this->assertFalse((bool) $theirs->fresh()->done);
    }

    public function test_who_may_create_tasks(): void
    {
        $task = ['title' => 'Do it', 'dueDate' => '2026-11-30', 'priority' => 'low'];

        foreach (['managerA', 'admin'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/tasks', $task)->assertCreated();
        }
        $this->as($this->agent)->postJson('/api/v1/tasks', $task + ['assignedToId' => $this->agent->id])->assertCreated();
        foreach (['ownerA', 'financeA', 'tenantUser'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/tasks', $task)->assertForbidden();
        }
        // technicians tick their own tasks but hold no right to create them
        $this->as($this->tech)->postJson('/api/v1/tasks', $task + ['assignedToId' => $this->tech->id])->assertForbidden();
    }

    public function test_tasks_can_be_filtered_and_come_back_in_due_date_order(): void
    {
        $this->task('t_late', 'prop_a', $this->agent->id, ['dueDate' => '2026-12-01', 'priority' => 'low']);
        $this->task('t_soon', 'prop_a', $this->agent->id, ['dueDate' => '2026-11-16', 'priority' => 'high', 'done' => true]);
        $this->task('t_mid', 'prop_a', $this->tech->id, ['dueDate' => '2026-11-25', 'priority' => 'high']);

        $this->assertSame(['t_soon', 't_mid', 't_late'], $this->taskIds($this->w->managerA));
        $this->assertEqualsCanonicalizing(['t_soon', 't_mid'], $this->taskIds($this->w->managerA, '&priority=high'));
        $this->assertSame(['t_soon'], $this->taskIds($this->w->managerA, '&done=1'));
        $this->assertSame(['t_mid'], $this->taskIds($this->w->managerA, '&assignedToId='.$this->tech->id));
    }

    // ======================= Notices ==========================================

    private function notice(string $id, ?string $property, string $audience, ?string $by = null, ?string $title = null): Notice
    {
        return Notice::query()->create([
            'id' => $id, 'title' => $title ?? "Notice {$id}", 'body' => 'Please note.', 'propertyId' => $property, 'audience' => $audience,
            'createdAt' => '2026-11-10T08:00:00+00:00', 'createdById' => $by ?? $this->w->managerA->id,
        ]);
    }

    private function noticeIds(User $as): array
    {
        return collect($this->as($as)->getJson('/api/v1/notices?per_page=100')->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_a_notice_records_who_posted_it_and_when_whatever_the_client_says(): void
    {
        $res = $this->as($this->w->managerA)->postJson('/api/v1/notices', [
            'title' => 'Water cut', 'body' => 'Tomorrow 9–12', 'propertyId' => 'prop_a', 'audience' => 'tenants',
            'createdById' => $this->w->ownerB->id, 'createdAt' => '1999-01-01T00:00:00+00:00',
        ])->assertCreated();

        $res->assertJsonPath('data.createdById', $this->w->managerA->id);
        $this->assertStringStartsWith('2026-11-15', $res->json('data.createdAt'));
    }

    public function test_a_notice_needs_a_title_body_and_a_known_audience(): void
    {
        $ok = ['title' => 'Hello', 'body' => 'World', 'propertyId' => 'prop_a', 'audience' => 'all'];
        $this->as($this->w->managerA);

        foreach (['title', 'body', 'audience'] as $field) {
            $payload = $ok;
            unset($payload[$field]);
            $this->postJson('/api/v1/notices', $payload)->assertStatus(422);
        }
        $this->postJson('/api/v1/notices', ['audience' => 'everybody'] + $ok)->assertStatus(422);
    }

    public function test_each_role_sees_only_the_notices_meant_for_it(): void
    {
        $all = $this->notice('n_all', 'prop_a', 'all');
        $owners = $this->notice('n_owners', 'prop_a', 'owners');
        $tenants = $this->notice('n_tenants', 'prop_a', 'tenants');
        $staff = $this->notice('n_staff', 'prop_a', 'staff');

        $this->assertEqualsCanonicalizing([$all->id, $owners->id], $this->noticeIds($this->w->ownerA));
        $this->assertEqualsCanonicalizing([$all->id, $tenants->id], $this->noticeIds($this->w->tenantUser));
        foreach (['managerA', 'financeA'] as $who) {
            $this->assertEqualsCanonicalizing([$all->id, $staff->id], $this->noticeIds($this->w->{$who}), $who);
        }
        $this->assertEqualsCanonicalizing([$all->id, $staff->id], $this->noticeIds($this->tech));
        $this->assertEqualsCanonicalizing([$all->id, $owners->id, $tenants->id, $staff->id], $this->noticeIds($this->w->admin));
    }

    public function test_notices_belong_to_a_property_and_do_not_cross_into_other_portfolios(): void
    {
        $a = $this->notice('n_a', 'prop_a', 'all');
        $b = $this->notice('n_b', 'prop_b', 'all', $this->w->ownerB->id);

        $this->assertSame([$a->id], $this->noticeIds($this->w->managerA));
        $this->assertSame([$a->id], $this->noticeIds($this->w->tenantUser));
        $this->assertSame([$b->id], $this->noticeIds($this->w->ownerB));
        $this->assertContains($this->as($this->w->managerA)->getJson("/api/v1/notices/{$b->id}")->status(), [403, 404]);
    }

    public function test_a_property_less_notice_reaches_the_posters_colleagues_not_other_portfolios_and_never_strangers(): void
    {
        $wide = $this->notice('n_wide', null, 'all', $this->w->managerA->id, 'Office closed Friday');

        foreach (['managerA', 'ownerA', 'financeA', 'tenantUser', 'admin'] as $who) {
            $this->assertContains($wide->id, $this->noticeIds($this->w->{$who}), $who);
        }
        $this->assertNotContains($wide->id, $this->noticeIds($this->w->ownerB), 'another owner’s people share nothing with the poster');
        $this->as($this->w->stranger);
        $this->assertSame([], collect($this->getJson('/api/v1/notices')->assertOk()->json('data'))->pluck('id')->all());
    }

    public function test_an_administrators_announcement_with_no_property_reaches_everyone_it_is_addressed_to(): void
    {
        $announcement = $this->notice('n_admin', null, 'all', $this->w->admin->id, 'Scheduled maintenance tonight');

        foreach (['managerA', 'ownerB', 'tenantUser', 'financeA'] as $who) {
            $this->assertContains($announcement->id, $this->noticeIds($this->w->{$who}), $who);
        }
    }

    public function test_who_may_post_notices_and_where(): void
    {
        $notice = ['title' => 'Hello', 'body' => 'World', 'audience' => 'all'];

        $this->as($this->w->managerA)->postJson('/api/v1/notices', $notice + ['propertyId' => 'prop_a'])->assertCreated();
        $this->as($this->w->managerA)->postJson('/api/v1/notices', $notice + ['propertyId' => 'prop_b'])->assertForbidden();
        foreach (['tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/notices', $notice + ['propertyId' => 'prop_a'])->assertForbidden();
        }
    }

    public function test_a_notice_can_be_corrected_by_staff_of_its_property_and_removed_only_by_an_admin(): void
    {
        $n = $this->notice('n_a', 'prop_a', 'all');

        $this->as($this->w->managerA)->patchJson("/api/v1/notices/{$n->id}", ['title' => 'Corrected'])->assertOk();
        $this->as($this->w->tenantUser)->patchJson("/api/v1/notices/{$n->id}", ['title' => 'Hijacked'])->assertForbidden();
        $this->assertContains($this->as($this->w->ownerB)->patchJson("/api/v1/notices/{$n->id}", ['title' => 'Hijacked'])->status(), [403, 404]);
        $this->assertSame('Corrected', $n->fresh()->title);

        $this->as($this->w->managerA)->deleteJson("/api/v1/notices/{$n->id}")->assertForbidden();
        $this->as($this->w->admin)->deleteJson("/api/v1/notices/{$n->id}")->assertOk();
    }
}

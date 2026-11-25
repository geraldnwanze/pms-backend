<?php

namespace Tests\Feature\Console;

use App\Jobs\QueueHeartbeat;
use App\Jobs\SendPushNotification;
use App\Mail\QueuedMarkdownMail;
use App\Models\Notification;
use App\Support\Heartbeat;
use App\Support\Modules;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\PmsTestCase;
use Tests\Support\World;

/** The scheduler and the queue worker: what is scheduled and when, that both prove they are alive, and that a failing job retries then lands in failed_jobs. */
class BackgroundWorkTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 09:01 is a minute at which only the every-minute heartbeats are due. `schedule:run` starts every other due command
        // as a separate process, which would act on the real database instead of this test's.
        Carbon::setTestNow('2026-11-24 09:01:00');
        Cache::flush();
    }

    public function test_the_tests_clock_only_makes_the_in_process_heartbeats_due(): void
    {
        $due = collect(app(Schedule::class)->dueEvents(app()))->map(fn (Event $e) => $e->description ?? $e->command)->sort()->values()->all();

        $this->assertSame(['pms:queue-heartbeat', 'pms:scheduler-heartbeat'], $due);
    }

    private function event(string $needle): Event
    {
        $found = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains($e->command ?? $e->description ?? '', $needle));
        $this->assertNotNull($found, "Nothing scheduled for [{$needle}].");

        return $found;
    }

    /** Use a real database queue instead of the test default, which runs jobs on the spot. */
    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
        app('queue')->setDefaultDriver('database');
    }

    // ---- the schedule -------------------------------------------------------------------------------------

    public function test_every_scheduled_command_runs_at_its_time_in_the_right_order_without_overlapping(): void
    {
        $expected = [
            'pms:generate-invoices' => '0 6 * * *',
            'pms:refresh-statuses' => '30 6 * * *',
            'pms:apply-late-fees' => '45 6 * * *',
            'pms:rent-reminders' => '0 7 * * *',
            'pms:lease-expiry-reminders' => '15 7 * * *',
            'pms:auto-debit' => '30 7 * * *',
            'pms:bill-utilities' => '15 6 1 * *',
            'queue:prune-failed' => '0 3 * * *',
        ];

        foreach ($expected as $command => $cron) {
            $event = $this->event($command);
            $this->assertSame($cron, $event->expression, $command);
            $this->assertTrue($event->withoutOverlapping, "{$command} must not overlap itself.");
        }
    }

    public function test_both_heartbeats_fire_every_minute(): void
    {
        // Three minutes of silence means "down", so anything slower than every minute would raise false alarms.
        $this->assertSame('* * * * *', $this->event('pms:scheduler-heartbeat')->expression);
        $this->assertSame('* * * * *', $this->event('pms:queue-heartbeat')->expression);
    }

    public function test_every_pms_command_that_does_work_is_on_the_schedule(): void
    {
        // The commands people run by hand are not scheduled; every other pms: command is.
        $manual = ['pms:make-superadmin', 'pms:status'];
        $scheduled = collect(app(Schedule::class)->events())->map(fn (Event $e) => (string) $e->command)->implode("\n");

        $missing = collect(array_keys(Artisan::all()))
            ->filter(fn ($name) => str_starts_with($name, 'pms:') && ! in_array($name, $manual, true))
            ->reject(fn ($name) => str_contains($scheduled, $name));

        $this->assertSame([], $missing->values()->all(), 'These pms: commands exist but are never scheduled.');
    }

    public function test_failed_jobs_are_kept_a_week_then_pruned(): void
    {
        DB::table('failed_jobs')->insert([
            ['uuid' => 'old', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(8)],
            ['uuid' => 'recent', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(6)],
        ]);

        // Run exactly what the schedule runs.
        $command = trim(preg_replace('/^.*artisan[\'"]?\s+/', '', $this->event('queue:prune-failed')->command));
        $this->assertSame(0, Artisan::call($command));

        $this->assertSame(['recent'], DB::table('failed_jobs')->pluck('uuid')->all());
    }

    public function test_the_dev_command_starts_the_scheduler_alongside_the_worker(): void
    {
        $this->artisan('dev:list')
            ->expectsOutputToContain('schedule:work')
            ->expectsOutputToContain('queue:listen')
            ->assertSuccessful();
    }

    // ---- proof of life ------------------------------------------------------------------------------------

    public function test_a_scheduler_tick_records_both_the_scheduler_and_the_worker(): void
    {
        $this->assertNull(Heartbeat::last(Heartbeat::SCHEDULER));

        $this->artisan('schedule:run')->assertSuccessful();      // the test queue runs jobs at once, standing in for a worker

        $this->assertEquals(now(), Heartbeat::last(Heartbeat::SCHEDULER));
        $this->assertEquals(now(), Heartbeat::last(Heartbeat::WORKER));
        $this->assertTrue(Heartbeat::isAlive(Heartbeat::SCHEDULER));
        $this->assertTrue(Heartbeat::isAlive(Heartbeat::WORKER));
    }

    public function test_the_worker_beat_only_happens_when_a_worker_runs_the_job(): void
    {
        $this->useDatabaseQueue();

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertNotNull(Heartbeat::last(Heartbeat::SCHEDULER), 'The scheduler beat is its own.');
        $this->assertNull(Heartbeat::last(Heartbeat::WORKER), 'Queued, not yet worked.');
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

        $this->assertEquals(now(), Heartbeat::last(Heartbeat::WORKER));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_beat_goes_stale_after_three_minutes(): void
    {
        Heartbeat::beat(Heartbeat::WORKER);

        Carbon::setTestNow(now()->addMinutes(3));
        $this->assertTrue(Heartbeat::isAlive(Heartbeat::WORKER), 'Three minutes is still fine.');

        Carbon::setTestNow(now()->addSecond());
        $this->assertFalse(Heartbeat::isAlive(Heartbeat::WORKER));
    }

    public function test_a_stale_heartbeat_job_is_not_run_late(): void
    {
        $job = new QueueHeartbeat;

        $this->assertEquals(now()->addMinutes(2), $job->retryUntil());
        $this->assertSame(1, $job->tries);
    }

    // ---- pms:status ---------------------------------------------------------------------------------------

    public function test_status_fails_when_nothing_has_ever_run(): void
    {
        $code = Artisan::call('pms:status');
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertSame(2, substr_count($output, 'DOWN'));
        $this->assertSame(2, substr_count($output, 'never'));
    }

    public function test_status_passes_when_both_are_running_and_counts_the_waiting_and_failed_jobs(): void
    {
        Heartbeat::beat(Heartbeat::SCHEDULER);
        Heartbeat::beat(Heartbeat::WORKER);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        DB::table('failed_jobs')->insert(['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        $this->artisan('pms:status')
            ->expectsOutputToContain('running')
            ->expectsOutputToContain('Waiting jobs: 1')
            ->expectsOutputToContain('Failed jobs:  1')
            ->assertSuccessful();
    }

    public function test_status_fails_when_only_the_worker_has_stopped(): void
    {
        Heartbeat::beat(Heartbeat::WORKER);
        Carbon::setTestNow(now()->addMinutes(5));
        Heartbeat::beat(Heartbeat::SCHEDULER);                  // the scheduler is fresh, the worker is five minutes old

        $this->artisan('pms:status')->assertFailed();
    }

    public function test_status_fails_when_only_the_scheduler_has_stopped(): void
    {
        Heartbeat::beat(Heartbeat::SCHEDULER);
        Carbon::setTestNow(now()->addMinutes(5));
        Heartbeat::beat(Heartbeat::WORKER);

        $this->artisan('pms:status')->assertFailed();
    }

    // ---- alerts to the superadmins ------------------------------------------------------------------------

    private function alerts(): Collection
    {
        return Notification::query()->where('kind', 'system')->where('forRole', 'superadmin')->orderBy('createdAt')->pluck('title');
    }

    public function test_a_stopped_worker_is_reported_to_the_superadmins_once_not_every_run(): void
    {
        Heartbeat::beat(Heartbeat::SCHEDULER);                       // the scheduler is what runs the check

        $this->artisan('pms:check-background')->assertSuccessful();
        $this->artisan('pms:check-background')->assertSuccessful();
        Carbon::setTestNow(now()->addMinutes(10));
        $this->artisan('pms:check-background')->assertSuccessful();

        $this->assertSame(['The queue worker has stopped'], $this->alerts()->all());
        $this->assertSame('superadmin', Notification::query()->where('kind', 'system')->value('forRole'));
        $this->assertNull(Notification::query()->where('kind', 'system')->value('forUserId'));
    }

    public function test_nothing_is_reported_while_the_worker_is_running(): void
    {
        Heartbeat::beat(Heartbeat::WORKER);

        $this->artisan('pms:check-background')->assertSuccessful();

        $this->assertSame([], $this->alerts()->all());
    }

    public function test_the_worker_coming_back_is_reported_and_a_second_outage_is_reported_again(): void
    {
        $this->artisan('pms:check-background');                      // down
        Heartbeat::beat(Heartbeat::WORKER);
        $this->artisan('pms:check-background');                      // back
        $this->artisan('pms:check-background');                      // still fine: nothing new

        Carbon::setTestNow(now()->addMinutes(10));
        $this->artisan('pms:check-background');                      // down again

        $this->assertSame(['The queue worker has stopped', 'The queue worker is back', 'The queue worker has stopped'],
            $this->alerts()->all());
    }

    public function test_new_failed_jobs_are_reported_once_and_again_only_when_there_are_more(): void
    {
        Heartbeat::beat(Heartbeat::WORKER);
        $fail = fn (string $uuid) => DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        $this->artisan('pms:check-background');                      // none yet
        $fail('a');
        $this->artisan('pms:check-background');
        $this->artisan('pms:check-background');                      // same one: not repeated
        $fail('b');
        $this->artisan('pms:check-background');

        $this->assertSame(['A background job failed', '2 background jobs have failed'], $this->alerts()->all());
    }

    public function test_clearing_the_failed_jobs_re_arms_the_alert(): void
    {
        Heartbeat::beat(Heartbeat::WORKER);
        $fail = fn (string $uuid) => DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        $fail('a');
        $this->artisan('pms:check-background');
        DB::table('failed_jobs')->delete();                          // queue:flush, or pruned after a week
        $this->artisan('pms:check-background');
        $fail('b');
        $this->artisan('pms:check-background');

        $this->assertSame(['A background job failed', 'A background job failed'], $this->alerts()->all());
    }

    public function test_the_alerts_reach_superadmins_but_not_other_roles(): void
    {
        $super = World::user('superadmin', 'super@pms.test');
        $this->artisan('pms:check-background');

        $this->as($super)->getJson('/api/v1/notifications')->assertOk()->assertJsonFragment(['title' => 'The queue worker has stopped']);
        $this->as($this->w->managerA)->getJson('/api/v1/notifications')->assertOk()->assertJsonMissing(['title' => 'The queue worker has stopped']);
    }

    public function test_the_check_runs_from_the_scheduler_every_five_minutes(): void
    {
        $event = $this->event('pms:check-background');

        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    // ---- the health endpoint for an uptime monitor --------------------------------------------------------

    public function test_the_background_health_endpoint_is_503_until_both_are_beating(): void
    {
        $this->getJson('/api/health/background')->assertStatus(503)->assertExactJson(['ok' => false, 'scheduler' => false, 'worker' => false]);

        Heartbeat::beat(Heartbeat::SCHEDULER);
        $this->getJson('/api/health/background')->assertStatus(503)->assertExactJson(['ok' => false, 'scheduler' => true, 'worker' => false]);

        Heartbeat::beat(Heartbeat::WORKER);
        $this->getJson('/api/health/background')->assertOk()->assertExactJson(['ok' => true, 'scheduler' => true, 'worker' => true]);

        Carbon::setTestNow(now()->addMinutes(4));
        $this->getJson('/api/health/background')->assertStatus(503)->assertExactJson(['ok' => false, 'scheduler' => false, 'worker' => false]);
    }

    // ---- the real scheduler, with a module off -------------------------------------------------------------

    public function test_the_rent_reminders_are_due_at_seven_and_do_nothing_while_the_module_is_off(): void
    {
        Carbon::setTestNow('2026-11-24 07:00:00');
        $this->w->invoice->update(['dueDate' => '2026-11-14']);       // ten days overdue
        $reminders = fn () => Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Rent overdue')->count();

        // The scheduler runs each command in its own process, so run the very command it would, here.
        $event = $this->event('pms:rent-reminders');
        $this->assertTrue($event->isDue(app()), 'Due at 07:00.');
        $command = trim(preg_replace('/^.*artisan[\'"]?\s+/', '', $event->command));

        app(Modules::class)->set('reminders', false);
        Artisan::call($command);
        $this->assertSame(0, $reminders());
        $this->assertStringContainsString('turned off', Artisan::output());

        app(Modules::class)->set('reminders', true);
        Artisan::call($command);
        $this->assertSame(1, $reminders());

        Carbon::setTestNow('2026-11-24 07:01:00');
        $this->assertFalse($event->isDue(app()), 'Not due a minute later.');
    }

    public function test_the_heartbeat_and_housekeeping_keep_running_with_every_module_off(): void
    {
        foreach (Modules::keys() as $key) {
            app(Modules::class)->set($key, false, cascade: true);
        }

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertNotNull(Heartbeat::last(Heartbeat::SCHEDULER));
        $this->assertNotNull(Heartbeat::last(Heartbeat::WORKER));
    }

    // ---- a job that keeps failing -------------------------------------------------------------------------

    public function test_an_email_that_keeps_failing_is_tried_three_times_then_kept_in_failed_jobs_and_logged(): void
    {
        $this->useDatabaseQueue();
        Mail::clearResolvedInstance('mail.manager');            // the base test case fakes mail; this test wants the real queueing
        app()->forgetInstance('mail.manager');
        Log::spy();

        Mail::to('someone@example.test')->send(new AlwaysFailingMail(['n' => 1]));
        $this->assertSame(1, DB::table('jobs')->count());

        foreach ([1, 2, 3] as $attempt) {
            $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

            if ($attempt < 3) {
                $this->assertSame(1, DB::table('jobs')->count(), "Attempt {$attempt} should leave it waiting to retry.");
                $this->assertSame(0, DB::table('failed_jobs')->count());
                Carbon::setTestNow(now()->addMinutes(3));        // past the 30s and 120s pauses
            }
        }

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, AlwaysFailingMail::class) && str_contains($m, 'smtp is down'))->once();
    }

    public function test_an_email_is_not_retried_straight_away(): void
    {
        $this->useDatabaseQueue();
        Mail::clearResolvedInstance('mail.manager');
        app()->forgetInstance('mail.manager');

        Mail::to('someone@example.test')->send(new AlwaysFailingMail);
        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

        // Released with the first pause (30s), so a worker looking right now finds nothing ready.
        $this->assertSame(now()->addSeconds(30)->timestamp, (int) DB::table('jobs')->value('available_at'));
        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('jobs')->value('attempts'));
    }

    public function test_every_job_has_a_timeout_shorter_than_the_queues_retry_window(): void
    {
        // If a job could run longer than `retry_after`, a second worker would pick it up while the first is still on it.
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $mail = new AlwaysFailingMail;

        foreach ([$mail->timeout, (new SendPushNotification('n'))->timeout, (new QueueHeartbeat)->timeout] as $timeout) {
            $this->assertGreaterThan(0, $timeout);
            $this->assertLessThan($retryAfter, $timeout);
        }
    }

    public function test_the_supervisor_and_systemd_files_run_the_worker_and_scheduler_safely(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $files = [
            base_path('deploy/supervisor/pms.conf'),
            base_path('deploy/systemd/pms-worker.service'),
            base_path('deploy/systemd/pms-scheduler.service'),
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $text = file_get_contents($file);

            if (str_contains($text, 'queue:work')) {
                preg_match('/queue:work[^\n]*--timeout=(\d+)/', $text, $m);
                $this->assertLessThan($retryAfter, (int) ($m[1] ?? PHP_INT_MAX), "{$file}: the worker timeout must be under retry_after ({$retryAfter}s).");
                $this->assertStringContainsString('--tries=3', $text);
                $this->assertMatchesRegularExpression('/(stopwaitsecs|TimeoutStopSec)=(\d+)/', $text);
                preg_match('/(?:stopwaitsecs|TimeoutStopSec)=(\d+)/', $text, $stop);
                $this->assertGreaterThanOrEqual((int) $m[1], (int) $stop[1], "{$file}: let the current job finish before the worker is killed.");
            }
        }

        $this->assertStringContainsString('schedule:work', file_get_contents($files[0]));
        $this->assertStringContainsString('schedule:work', file_get_contents($files[2]));
    }

    public function test_every_queued_email_inherits_the_retry_rules(): void
    {
        $mail = new AlwaysFailingMail;

        $this->assertSame(3, $mail->tries);
        $this->assertSame([30, 120], $mail->backoff);
        $this->assertSame(30, $mail->timeout);
        $this->assertSame(2, (new SendPushNotification('n'))->tries);
        $this->assertSame(30, (new SendPushNotification('n'))->backoff);
    }
}

class AlwaysFailingMail extends QueuedMarkdownMail
{
    protected string $template = 'emails.invitation';

    protected function subjectLine(): string
    {
        return 'Never arrives';
    }

    public function build(): static
    {
        throw new RuntimeException('smtp is down');
    }
}

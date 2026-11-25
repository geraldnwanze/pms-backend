<?php

use App\Jobs\QueueHeartbeat;
use App\Support\Heartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily transactional-email sweeps. Emails are queued, so these just enqueue
// jobs for the `queue:work` worker to send. Requires `php artisan schedule:work`
// (or a system cron running `schedule:run`) to fire.
// Invoices are issued first, then statuses refreshed, then the reminder sweeps run on up-to-date records.
Schedule::command('pms:bill-utilities')->monthlyOn(1, '06:15')->withoutOverlapping();
Schedule::command('pms:generate-invoices')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('pms:refresh-statuses')->dailyAt('06:30')->withoutOverlapping();
Schedule::command('pms:apply-late-fees')->dailyAt('06:45')->withoutOverlapping();
Schedule::command('pms:rent-reminders')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('pms:lease-expiry-reminders')->dailyAt('07:15')->withoutOverlapping();
Schedule::command('pms:auto-debit')->dailyAt('07:30')->withoutOverlapping();

// Housekeeping. Failed jobs are kept a week for inspection (`queue:failed`, `queue:retry`), then dropped.
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:00')->withoutOverlapping();

// Proof of life, read by `pms:status`: the scheduler ticking, and a worker running the job the scheduler queues.
Schedule::call(fn () => Heartbeat::beat(Heartbeat::SCHEDULER))->name('pms:scheduler-heartbeat')->everyMinute();
Schedule::job(new QueueHeartbeat)->name('pms:queue-heartbeat')->everyMinute();

// Tells the superadmins, in the app, when the worker stops or jobs start failing.
Schedule::command('pms:check-background')->everyFiveMinutes()->withoutOverlapping();

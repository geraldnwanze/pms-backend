<?php

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

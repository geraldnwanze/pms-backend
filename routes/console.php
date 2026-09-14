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
Schedule::command('pms:rent-reminders')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('pms:lease-expiry-reminders')->dailyAt('07:15')->withoutOverlapping();

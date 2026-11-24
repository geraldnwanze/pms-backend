<?php

namespace App\Console\Commands;

use App\Console\Concerns\SkipsWhenModuleOff;
use App\Services\ReminderService;
use Illuminate\Console\Command;

/**
 * Queues a lease-expiry reminder to each tenant whose active lease ends within
 * the next N days (default 60). Same logic as the on-demand
 * POST /reminders/expiring-leases endpoint (ReminderService::sendExpiringLeases).
 */
class SendLeaseExpiryReminders extends Command
{
    use SkipsWhenModuleOff;

    protected $signature = 'pms:lease-expiry-reminders {--days=60 : How many days ahead to warn}';

    protected $description = 'Send lease-expiring reminders to tenants (in-app + queued email)';

    public function handle(ReminderService $reminders): int
    {
        if ($this->moduleIsOff('reminders')) {
            return self::SUCCESS;
        }

        $result = $reminders->sendExpiringLeases(null, null, (int) $this->option('days'));

        $this->info("Lease-expiry reminders: {$result['notified']} in-app, {$result['emailed']} email(s) queued.");

        return self::SUCCESS;
    }
}

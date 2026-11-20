<?php

namespace App\Console\Commands;

use App\Services\StatusRefreshService;
use Illuminate\Console\Command;

/**
 * Keeps calendar-driven statuses honest: unpaid invoices past their due date become overdue,
 * leases past their end date become expired (and those about to end, expiring). Runs daily,
 * before the reminder sweeps so they see up-to-date records.
 */
class RefreshStatuses extends Command
{
    protected $signature = 'pms:refresh-statuses {--dry-run : Report what would change without changing anything}';

    protected $description = 'Mark invoices overdue and leases expiring/expired according to today’s date (daily)';

    public function handle(StatusRefreshService $statuses): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = $statuses->refresh($dry);

        $this->info(($dry ? '[dry run] ' : '')
            ."Invoices: {$r['overdue']} overdue, {$r['reopened']} back to unpaid. "
            ."Leases: {$r['expired']} expired, {$r['expiring']} expiring, {$r['reactivated']} back to active."
            .($r['finalBills'] ? " {$r['finalBills']} final utility bill".($r['finalBills'] === 1 ? '' : 's').' raised.' : ''));

        return self::SUCCESS;
    }
}

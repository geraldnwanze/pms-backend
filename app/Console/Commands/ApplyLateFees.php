<?php

namespace App\Console\Commands;

use App\Console\Concerns\SkipsWhenModuleOff;
use App\Services\LateFeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Charges the late fee on invoices that are past their grace period and still unpaid. Runs daily, after the
 * status refresh, and is safe to re-run: an invoice is charged once.
 */
class ApplyLateFees extends Command
{
    use SkipsWhenModuleOff;

    protected $signature = 'pms:apply-late-fees
        {--dry-run : List the fees that would be charged without charging any}
        {--date= : Pretend today is this date (YYYY-MM-DD) — not in production}';

    protected $description = 'Charge the late fee on overdue invoices past their grace period (daily)';

    public function handle(LateFeeService $fees): int
    {
        if ($this->moduleIsOff('reminders')) {
            return self::SUCCESS;
        }

        if ($this->option('date') && app()->environment('production')) {
            $this->error('--date is a testing switch and is disabled in production.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $r = $fees->run($dry, $this->option('date') ? Carbon::parse($this->option('date')) : null);

        foreach ($r['log'] as $line) {
            $this->line("  {$line}");
        }
        $this->info(($dry ? '[dry run] ' : '')."Late fees: {$r['applied']} ".($dry ? 'to charge' : 'charged').' (₦'.number_format($r['total'])."), {$r['skipped']} invoice(s) not due one.");

        return self::SUCCESS;
    }
}

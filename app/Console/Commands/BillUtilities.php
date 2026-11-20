<?php

namespace App\Console\Commands;

use App\Services\UtilityBillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Invoices tenants for the meter use read since each meter was last billed. Runs monthly; safe to re-run. */
class BillUtilities extends Command
{
    protected $signature = 'pms:bill-utilities
        {--dry-run : Show what would be billed without billing it}
        {--date= : Pretend today is this date (YYYY-MM-DD) — not in production}';

    protected $description = 'Invoice tenants for unbilled meter readings (monthly)';

    public function handle(UtilityBillingService $billing): int
    {
        if ($this->option('date') && app()->environment('production')) {
            $this->error('--date is a testing switch and is disabled in production.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $r = $billing->run($dry, $this->option('date') ? Carbon::parse($this->option('date')) : null);

        foreach ($r['log'] as $line) {
            $this->line("  {$line}");
        }
        foreach ($r['skipped'] as $line) {
            $this->warn("  skipped — {$line}");
        }
        $this->info(($dry ? '[dry run] ' : '')."Utilities: {$r['invoices']} invoice(s) for {$r['meters']} meter(s), ₦".number_format($r['total']).'.');

        return self::SUCCESS;
    }
}

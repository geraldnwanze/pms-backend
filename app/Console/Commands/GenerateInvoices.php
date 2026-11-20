<?php

namespace App\Console\Commands;

use App\Services\InvoiceGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Issues each current lease's rent invoice for the coming (or current) billing period. Runs daily, before
 * the status refresh and the reminder sweeps, and is safe to re-run: a period is never invoiced twice.
 */
class GenerateInvoices extends Command
{
    protected $signature = 'pms:generate-invoices
        {--dry-run : List the invoices that would be issued without issuing any}
        {--date= : Pretend today is this date (YYYY-MM-DD) — not in production}';

    protected $description = 'Issue rent invoices for upcoming billing periods (daily)';

    public function handle(InvoiceGenerationService $invoicing): int
    {
        if ($this->option('date') && app()->environment('production')) {
            $this->error('--date is a testing switch and is disabled in production.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $result = $invoicing->run($dry, $this->option('date') ? Carbon::parse($this->option('date')) : null);

        foreach ($result['log'] as $line) {
            $this->line("  {$line}");
        }

        $this->info(($dry ? '[dry run] ' : '')."Invoices: {$result['created']} ".($dry ? 'to issue' : 'issued').", {$result['skipped']} already invoiced. "
            ."{$result['unbillable']} lease(s) can't be billed automatically (custom cycle, no tenant or no rent).");

        return self::SUCCESS;
    }
}

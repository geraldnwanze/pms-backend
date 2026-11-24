<?php

namespace App\Console\Commands;

use App\Console\Concerns\SkipsWhenModuleOff;
use App\Services\AutoDebitService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Charges the saved cards of enrolled tenants for invoices that fall due (and retries failures).
 * Runs daily from the scheduler. The testing switches are refused in production.
 */
class RunAutoDebit extends Command
{
    use SkipsWhenModuleOff;

    protected $signature = 'pms:auto-debit
        {--dry-run : List what would be charged without charging anything}
        {--invoice= : Only this invoice (id or code)}
        {--date= : Pretend today is this date (YYYY-MM-DD) — not in production}
        {--force : Ignore due-date / enrolment-date rules for the chosen invoice — not in production}
        {--amount= : With --force, charge only this many naira (e.g. to test a real card cheaply) — not in production}';

    protected $description = 'Charge enrolled tenants’ saved cards for rent invoices that are due (daily)';

    public function handle(AutoDebitService $autoDebit): int
    {
        if ($this->moduleIsOff('auto-debit')) {
            return self::SUCCESS;
        }

        $testing = $this->option('date') || $this->option('force') || $this->option('amount');

        if ($testing && app()->environment('production')) {
            $this->error('--date, --force and --amount are testing switches and are disabled in production.');

            return self::FAILURE;
        }
        if (($this->option('force') || $this->option('amount')) && ! $this->option('invoice')) {
            $this->error('--force and --amount need --invoice=… so they can only ever touch one invoice.');

            return self::FAILURE;
        }
        if ($this->option('amount') && ! $this->option('force')) {
            $this->error('--amount only applies together with --force.');

            return self::FAILURE;
        }

        $r = $autoDebit->run([
            'date' => $this->option('date') ? Carbon::parse($this->option('date')) : null,
            'invoice' => $this->option('invoice'),
            'force' => (bool) $this->option('force'),
            'dryRun' => (bool) $this->option('dry-run'),
            'amount' => $this->option('amount') ? (int) $this->option('amount') : null,
        ]);

        foreach ($r['log'] as $line) {
            $this->line("  {$line}");
        }

        $this->info(sprintf(
            'Auto-debit%s: %d card(s) | charged %d (ok %d, failed %d, pending %d) | final failures %d | reminders %d | expired cards %d | reconciled %d | skipped %d',
            $this->option('dry-run') ? ' (dry run)' : '',
            $r['mandates'], $r['charged'], $r['succeeded'], $r['failed'], $r['pending'],
            $r['finalFailures'], $r['reminded'], $r['expired'], $r['reconciled'], $r['skipped'],
        ));

        return self::SUCCESS;
    }
}

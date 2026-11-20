<?php

namespace Tests\Feature\AutoDebit;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class RunAutoDebitCommandTest extends AutoDebitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mandate();
        $this->dueOn('2026-11-11');
    }

    private function artisanRun(array $args): array
    {
        $code = Artisan::call('pms:auto-debit', $args);

        return [$code, Artisan::output()];
    }

    public function test_the_job_is_scheduled_daily_in_the_morning(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'pms:auto-debit'));

        $this->assertNotNull($event, 'pms:auto-debit is not scheduled');
        $this->assertSame('30 7 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_a_normal_run_charges_what_is_due_and_reports_counts(): void
    {
        [$code, $out] = $this->artisanRun(['--date' => '2026-11-11']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('charged 1 (ok 1, failed 0, pending 0)', $out);
        $this->assertCount(1, $this->chargeCalls());
    }

    public function test_dry_run_touches_nothing(): void
    {
        [$code, $out] = $this->artisanRun(['--dry-run' => true, '--date' => '2026-11-11']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('would charge', $out);
        $this->assertCount(0, $this->chargeCalls());
        $this->assertCount(0, $this->attempts());
    }

    public function test_force_and_amount_need_an_invoice(): void
    {
        [$code, $out] = $this->artisanRun(['--force' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--invoice', $out);

        [$code] = $this->artisanRun(['--amount' => 100, '--force' => true]);
        $this->assertSame(1, $code);

        $this->assertCount(0, $this->chargeCalls());
    }

    public function test_amount_needs_force(): void
    {
        [$code, $out] = $this->artisanRun(['--amount' => 100, '--invoice' => $this->w->invoice->id]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--force', $out);
        $this->assertCount(0, $this->chargeCalls());
    }

    public function test_force_with_amount_charges_only_that_much_of_that_invoice(): void
    {
        $this->w->invoice(['id' => 'inv_other', 'dueDate' => '2030-01-01']);

        [$code] = $this->artisanRun(['--force' => true, '--amount' => 100, '--invoice' => $this->w->invoice->id]);

        $this->assertSame(0, $code);
        $this->assertCount(1, $this->chargeCalls());
        $this->assertSame(10_000, $this->chargeCalls()[0]['data']['amount']);   // ₦100 in kobo
    }

    public function test_force_ignores_the_due_date_but_only_for_the_chosen_invoice(): void
    {
        $this->dueOn('2030-01-01');
        $other = $this->w->invoice(['id' => 'inv_future', 'dueDate' => '2030-01-01']);

        $this->artisanRun(['--force' => true, '--invoice' => $this->w->invoice->id, '--date' => '2026-11-11']);

        $this->assertCount(1, $this->attempts());
        $this->assertCount(0, $this->attempts($other));
    }

    public function test_the_testing_switches_are_refused_in_production(): void
    {
        $this->app['env'] = 'production';

        foreach ([
            ['--date' => '2026-11-11'],
            ['--force' => true, '--invoice' => $this->w->invoice->id],
            ['--amount' => 100, '--force' => true, '--invoice' => $this->w->invoice->id],
        ] as $args) {
            [$code, $out] = $this->artisanRun($args);

            $this->assertSame(1, $code, json_encode($args));
            $this->assertStringContainsString('disabled in production', $out);
        }

        $this->assertCount(0, $this->chargeCalls());
        $this->assertCount(0, $this->attempts());
    }

    public function test_a_plain_run_still_works_in_production(): void
    {
        $this->app['env'] = 'production';
        Carbon::setTestNow('2026-11-11 07:30:00');

        [$code] = $this->artisanRun([]);

        $this->assertSame(0, $code);
        $this->assertCount(1, $this->chargeCalls());
    }
}

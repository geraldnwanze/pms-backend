<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Lease;
use App\Services\StatusRefreshService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;

/** `pms:refresh-statuses`: calendar-driven statuses (overdue, expiring, expired) catch up with today's date. */
class StatusRefreshTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->w->invoice->delete();
        $this->w->lease->delete();
        $this->w->leaseB->delete();
    }

    private function invoice(string $id, array $attrs = []): Invoice
    {
        return $this->w->invoice(array_merge(['id' => $id, 'leaseId' => null], $attrs));
    }

    private function lease(string $id, string $status, string $end): Lease
    {
        return Lease::query()->create([
            'id' => $id, 'code' => strtoupper($id), 'unitId' => 'unit_a', 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada',
            'startDate' => '2025-01-01', 'endDate' => $end, 'rent' => 1_000_000, 'billingCycle' => 'annual',
            'status' => $status, 'signed' => true,
        ]);
    }

    private function run_(): array
    {
        return app(StatusRefreshService::class)->refresh();
    }

    // ---- Invoices -----------------------------------------------------------

    public function test_unpaid_invoices_past_their_due_day_become_overdue(): void
    {
        $this->invoice('inv_late', ['dueDate' => '2026-11-14', 'status' => 'unpaid']);

        $result = $this->run_();

        $this->assertSame('overdue', Invoice::query()->find('inv_late')->status);
        $this->assertSame(1, $result['overdue']);
    }

    public function test_invoices_due_today_or_later_are_left_unpaid(): void
    {
        $this->invoice('inv_today', ['dueDate' => '2026-11-15', 'status' => 'unpaid']);
        $this->invoice('inv_later', ['dueDate' => '2026-12-01', 'status' => 'unpaid']);

        $this->assertSame(0, $this->run_()['overdue']);

        $this->assertSame('unpaid', Invoice::query()->find('inv_today')->status);
        $this->assertSame('unpaid', Invoice::query()->find('inv_later')->status);
    }

    public function test_an_overdue_invoice_whose_due_date_was_moved_out_goes_back_to_unpaid(): void
    {
        $this->invoice('inv_moved', ['dueDate' => '2026-12-20', 'status' => 'overdue']);

        $this->assertSame(1, $this->run_()['reopened']);
        $this->assertSame('unpaid', Invoice::query()->find('inv_moved')->status);
    }

    public function test_paid_partial_and_cancelled_invoices_are_never_touched(): void
    {
        $this->invoice('inv_paid', ['dueDate' => '2026-01-01', 'status' => 'fully-paid', 'amountPaid' => 100_000]);
        $this->invoice('inv_part', ['dueDate' => '2026-01-01', 'status' => 'partially-paid', 'amountPaid' => 40_000]);
        $this->invoice('inv_void', ['dueDate' => '2026-01-01', 'status' => 'cancelled']);

        $result = $this->run_();

        $this->assertSame(0, $result['overdue']);
        $this->assertSame('fully-paid', Invoice::query()->find('inv_paid')->status);
        $this->assertSame('partially-paid', Invoice::query()->find('inv_part')->status);
        $this->assertSame('cancelled', Invoice::query()->find('inv_void')->status);
    }

    public function test_an_unpaid_invoice_that_actually_has_money_on_it_is_corrected_not_called_overdue(): void
    {
        $this->invoice('inv_stale', ['dueDate' => '2026-01-01', 'status' => 'unpaid', 'amountPaid' => 100_000]);

        $this->run_();

        $this->assertSame('fully-paid', Invoice::query()->find('inv_stale')->status);
    }

    // ---- Leases -------------------------------------------------------------

    public function test_leases_past_their_end_date_expire_including_renewed_ones(): void
    {
        $this->lease('l_old', 'active', '2026-11-14');
        $this->lease('l_exp', 'expiring', '2026-10-01');
        $this->lease('l_ren', 'renewed', '2026-06-30');

        $this->assertSame(3, $this->run_()['expired']);

        foreach (['l_old', 'l_exp', 'l_ren'] as $id) {
            $this->assertSame('expired', Lease::query()->find($id)->status, $id);
        }
    }

    public function test_a_lease_ending_today_has_not_expired_yet_but_is_expiring(): void
    {
        $this->lease('l_today', 'active', '2026-11-15');

        $result = $this->run_();

        $this->assertSame(0, $result['expired']);
        $this->assertSame('expiring', Lease::query()->find('l_today')->status);
    }

    public function test_active_leases_ending_within_sixty_days_become_expiring_and_later_ones_do_not(): void
    {
        $this->lease('l_in60', 'active', '2027-01-14');     // exactly 60 days out
        $this->lease('l_in61', 'active', '2027-01-15');
        $this->lease('l_far', 'active', '2027-09-01');

        $this->assertSame(1, $this->run_()['expiring']);

        $this->assertSame('expiring', Lease::query()->find('l_in60')->status);
        $this->assertSame('active', Lease::query()->find('l_in61')->status);
        $this->assertSame('active', Lease::query()->find('l_far')->status);
    }

    public function test_an_expiring_lease_whose_end_date_was_pushed_out_is_active_again(): void
    {
        $this->lease('l_pushed', 'expiring', '2027-06-01');

        $this->assertSame(1, $this->run_()['reactivated']);
        $this->assertSame('active', Lease::query()->find('l_pushed')->status);
    }

    public function test_terminated_and_already_expired_leases_and_renewed_ones_still_running_are_left_alone(): void
    {
        $this->lease('l_term', 'terminated', '2026-01-01');
        $this->lease('l_done', 'expired', '2026-01-01');
        $this->lease('l_ren', 'renewed', '2026-12-01');    // renewed and ending soon: stays renewed

        $result = $this->run_();

        $this->assertSame(0, $result['expired'] + $result['expiring'] + $result['reactivated']);
        $this->assertSame('terminated', Lease::query()->find('l_term')->status);
        $this->assertSame('expired', Lease::query()->find('l_done')->status);
        $this->assertSame('renewed', Lease::query()->find('l_ren')->status);
    }

    public function test_expiring_a_lease_does_not_free_its_unit(): void
    {
        $this->w->uA->forceFill(['status' => 'occupied'])->save();
        $this->lease('l_old', 'active', '2026-01-01');

        $this->run_();

        $this->assertSame('occupied', $this->w->uA->fresh()->status, 'only terminating releases the unit; an overstaying tenant still holds it');
    }

    // ---- The command --------------------------------------------------------

    public function test_the_command_reports_what_it_did_and_is_safe_to_rerun(): void
    {
        $this->invoice('inv_late', ['dueDate' => '2026-11-01', 'status' => 'unpaid']);
        $this->lease('l_old', 'active', '2026-11-01');

        $this->artisan('pms:refresh-statuses')
            ->expectsOutput('Invoices: 1 overdue, 0 back to unpaid. Leases: 1 expired, 0 expiring, 0 back to active.')
            ->assertSuccessful();

        $this->artisan('pms:refresh-statuses')
            ->expectsOutput('Invoices: 0 overdue, 0 back to unpaid. Leases: 0 expired, 0 expiring, 0 back to active.')
            ->assertSuccessful();
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->invoice('inv_late', ['dueDate' => '2026-11-01', 'status' => 'unpaid']);
        $this->lease('l_old', 'active', '2026-11-01');

        $this->artisan('pms:refresh-statuses', ['--dry-run' => true])
            ->expectsOutput('[dry run] Invoices: 1 overdue, 0 back to unpaid. Leases: 1 expired, 0 expiring, 0 back to active.')
            ->assertSuccessful();

        $this->assertSame('unpaid', Invoice::query()->find('inv_late')->status);
        $this->assertSame('active', Lease::query()->find('l_old')->status);
    }

    public function test_it_is_scheduled_daily_before_the_reminder_sweeps(): void
    {
        $events = collect(app(Schedule::class)->events());
        $time = fn (string $needle) => $events->first(fn ($e) => str_contains($e->command, $needle))?->expression;

        $this->assertSame('30 6 * * *', $time('pms:refresh-statuses'));
        $this->assertSame('0 7 * * *', $time('pms:rent-reminders'));
    }
}

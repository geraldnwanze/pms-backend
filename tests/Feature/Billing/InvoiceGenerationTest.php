<?php

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Notification;
use App\Models\Unit;
use App\Services\InvoiceGenerationService;
use App\Services\StatusRefreshService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\PmsTestCase;

/** Rent invoices are issued on schedule: right amounts, right periods, never twice, never back-billed. */
class InvoiceGenerationTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');

        Invoice::query()->delete();
        Lease::query()->whereIn('id', ['lease_a', 'lease_b'])->delete();
    }

    private function unit(string $id, int $service = 120_000): Unit
    {
        return Unit::query()->create(['id' => $id, 'buildingId' => 'bld_a', 'propertyId' => 'prop_a', 'code' => strtoupper($id), 'type' => 'Flat', 'status' => 'occupied', 'rent' => 100_000, 'serviceCharge' => $service]);
    }

    private function lease(string $id, string $cycle, string $start, string $end, array $over = []): Lease
    {
        $unit = $over['unitId'] ?? "u_{$id}";
        Unit::query()->find($unit) ?? $this->unit($unit);

        return Lease::query()->create($over + [
            'id' => $id, 'code' => strtoupper($id), 'unitId' => $unit, 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada',
            'startDate' => $start, 'endDate' => $end, 'rent' => 1_200_000, 'billingCycle' => $cycle, 'status' => 'active', 'signed' => true,
        ]);
    }

    private function generate(?string $date = null, bool $dry = false): array
    {
        return app(InvoiceGenerationService::class)->run($dry, $date ? Carbon::parse($date) : null);
    }

    private function invoices(string $lease): Collection
    {
        return Invoice::query()->where('leaseId', $lease)->orderBy('periodStart')->get();
    }

    // ---- The basics ------------------------------------------------------------------

    public function test_a_monthly_lease_is_invoiced_for_the_current_month(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');

        $result = $this->generate();

        $this->assertSame(1, $result['created']);
        $inv = $this->invoices('l1')->sole();
        $this->assertSame(['2026-11-01', '2026-11-30'], [$inv->periodStart, $inv->periodEnd]);
        $this->assertSame(110_000, (int) $inv->total);
        $this->assertSame([['rent', 100_000], ['service-charge', 10_000]], collect($inv->lineItems)->map(fn ($i) => [$i['type'], (int) $i['amount']])->all());
        $this->assertSame(['2026-11-15', '2026-11-15'], [$inv->issueDate, $inv->dueDate], 'the month has begun, so it is due now');
        $this->assertSame(['unpaid', 'monthly', 0], [$inv->status, $inv->cycle, (int) $inv->amountPaid]);
        $this->assertSame(['prop_a', 'u_l1', 'ten_ada'], [$inv->propertyId, $inv->unitId, $inv->tenantId]);
        $this->assertMatchesRegularExpression('/^INV-[A-Z0-9]{5}$/', $inv->code);
    }

    public function test_the_next_period_is_invoiced_two_weeks_before_it_starts_and_due_on_its_first_day(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');
        $this->generate('2026-11-15');
        $this->assertCount(1, $this->invoices('l1'), 'December is 16 days away: not yet');

        $this->generate('2026-11-17');                                  // exactly 14 days before 1 Dec

        $dec = $this->invoices('l1')->last();
        $this->assertCount(2, $this->invoices('l1'));
        $this->assertSame(['2026-12-01', '2026-12-31', '2026-11-17', '2026-12-01'], [$dec->periodStart, $dec->periodEnd, $dec->issueDate, $dec->dueDate]);
    }

    public function test_a_period_that_has_not_opened_for_invoicing_is_left_alone(): void
    {
        $this->lease('l1', 'monthly', '2026-12-01', '2027-11-30');

        $this->assertSame(0, $this->generate('2026-11-16')['created']);
        $this->assertSame(1, $this->generate('2026-11-17')['created']);
        $this->assertSame('2026-12-01', $this->invoices('l1')->sole()->dueDate);
    }

    public function test_periods_that_are_already_over_are_never_back_billed(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');          // June to October passed with no invoices

        $this->generate();

        $this->assertSame(['2026-11-01'], $this->invoices('l1')->pluck('periodStart')->all());
    }

    // ---- Cycles and amounts ------------------------------------------------------------

    public function test_rent_is_the_annual_rent_shared_out_by_the_months_in_the_period(): void
    {
        $this->lease('q', 'quarterly', '2026-10-01', '2027-09-30');
        $this->lease('h', 'half-yearly', '2026-09-01', '2027-08-31');
        $this->lease('a', 'annual', '2026-03-01', '2027-02-28');

        $this->generate();

        $rent = fn (string $l) => (int) collect($this->invoices($l)->sole()->lineItems)->firstWhere('type', 'rent')['amount'];
        $this->assertSame(300_000, $rent('q'));
        $this->assertSame(600_000, $rent('h'));
        $this->assertSame(1_200_000, $rent('a'));
        $this->assertSame(['2026-10-01', '2026-12-31'], [$this->invoices('q')->sole()->periodStart, $this->invoices('q')->sole()->periodEnd]);
        $this->assertSame(['2026-09-01', '2027-02-28'], [$this->invoices('h')->sole()->periodStart, $this->invoices('h')->sole()->periodEnd]);
        $this->assertSame(['2026-03-01', '2027-02-28'], [$this->invoices('a')->sole()->periodStart, $this->invoices('a')->sole()->periodEnd]);
    }

    public function test_the_annual_service_charge_is_shared_out_the_same_way(): void
    {
        $this->lease('m', 'monthly', '2026-06-01', '2027-05-31');
        $this->lease('q', 'quarterly', '2026-10-01', '2027-09-30');
        $this->lease('a', 'annual', '2026-03-01', '2027-02-28');

        $this->generate();

        $service = fn (string $l) => (int) collect($this->invoices($l)->sole()->lineItems)->firstWhere('type', 'service-charge')['amount'];
        $this->assertSame([10_000, 30_000, 120_000], [$service('m'), $service('q'), $service('a')]);
    }

    public function test_an_annual_rent_is_never_billed_as_if_it_were_monthly(): void
    {
        // a flat at ₦3.8m a year, billed monthly: about ₦317k, not ₦3.8m
        $this->unit('u_real', 460_000);
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31', ['unitId' => 'u_real', 'rent' => 3_800_000]);

        $this->generate();

        $items = collect($this->invoices('l1')->sole()->lineItems)->keyBy('type');
        $this->assertSame(316_667, (int) $items['rent']['amount']);
        $this->assertSame(38_333, (int) $items['service-charge']['amount']);
    }

    public function test_a_years_worth_of_monthly_invoices_adds_up_to_exactly_the_annual_figures(): void
    {
        $this->unit('u_odd', 100_001);
        $this->lease('l1', 'monthly', '2027-01-01', '2027-12-31', ['unitId' => 'u_odd', 'rent' => 1_000_003]);

        for ($day = Carbon::parse('2026-12-10'); $day->lt('2027-12-20'); $day->addDays(10)) {
            $this->generate($day->toDateString());
        }

        $invoices = $this->invoices('l1');
        $sum = fn (string $type) => $invoices->sum(fn ($i) => (int) collect($i->lineItems)->firstWhere('type', $type)['amount']);
        $this->assertCount(12, $invoices);
        $this->assertSame(1_000_003, $sum('rent'), 'no naira lost or gained to rounding');
        $this->assertSame(100_001, $sum('service-charge'));
    }

    public function test_a_unit_with_no_service_charge_gets_a_rent_only_invoice(): void
    {
        $this->unit('u_bare', 0);
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31', ['unitId' => 'u_bare']);

        $this->generate();

        $inv = $this->invoices('l1')->sole();
        $this->assertCount(1, $inv->lineItems);
        $this->assertSame(100_000, (int) $inv->total);
    }

    public function test_the_last_period_is_prorated_by_days_when_the_lease_ends_part_way(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2026-11-20');         // 20 of November's 30 days

        $this->generate();

        $inv = $this->invoices('l1')->sole();
        $this->assertSame(['2026-11-01', '2026-11-20'], [$inv->periodStart, $inv->periodEnd]);
        $this->assertSame([66_667, 6_667], collect($inv->lineItems)->map(fn ($i) => (int) $i['amount'])->all());
        $this->assertSame(73_334, (int) $inv->total);
    }

    public function test_a_quarterly_lease_that_ends_part_way_through_the_quarter_is_prorated_against_the_quarter(): void
    {
        $this->lease('l1', 'quarterly', '2026-10-01', '2026-11-20');        // 51 of the quarter's 92 days

        $this->generate();

        $inv = $this->invoices('l1')->sole();
        $this->assertSame(['2026-10-01', '2026-11-20'], [$inv->periodStart, $inv->periodEnd]);
        $this->assertSame([166_304, 16_630], collect($inv->lineItems)->map(fn ($i) => (int) $i['amount'])->all());   // 300,000 and 30,000 × 51/92
    }

    public function test_a_lease_shorter_than_its_cycle_is_prorated_too(): void
    {
        $this->lease('l1', 'monthly', '2026-11-10', '2026-11-25');          // 16 of the 30 days from 10 Nov

        $this->generate();

        $this->assertSame(53_333, (int) collect($this->invoices('l1')->sole()->lineItems)->firstWhere('type', 'rent')['amount']);
    }

    public function test_nothing_is_invoiced_beyond_the_end_of_the_lease_or_for_one_that_has_already_ended(): void
    {
        $this->lease('ends', 'monthly', '2026-06-01', '2026-11-30');
        $this->lease('gone', 'monthly', '2026-01-01', '2026-10-31');         // still marked active, but over

        $this->generate('2026-11-17');

        $this->assertSame(['2026-11-01'], $this->invoices('ends')->pluck('periodStart')->all(), 'no December invoice for a lease that ends on 30 November');
        $this->assertCount(0, $this->invoices('gone'));
    }

    public function test_a_lease_ending_on_its_anniversary_gets_exactly_a_years_invoices_and_no_one_day_stub(): void
    {
        // what the lease form produces by default: 12 Nov 2026 to 12 Nov 2027
        $this->lease('m', 'monthly', '2026-11-12', '2027-11-12');
        $this->lease('a', 'annual', '2026-11-12', '2027-11-12');

        for ($day = Carbon::parse('2026-11-01'); $day->lt('2027-11-30'); $day->addDays(10)) {
            $this->generate($day->toDateString());
        }

        $monthly = $this->invoices('m');
        $this->assertCount(12, $monthly, 'twelve months, not thirteen');
        $this->assertSame('2027-11-11', $monthly->last()->periodEnd);
        $this->assertNotContains('2027-11-12', $monthly->pluck('periodStart')->all());
        $this->assertSame(1_200_000, $monthly->sum(fn ($i) => (int) collect($i->lineItems)->firstWhere('type', 'rent')['amount']), 'exactly the annual rent');

        $annual = $this->invoices('a');
        $this->assertCount(1, $annual);
        $this->assertSame(['2026-11-12', '2027-11-11'], [$annual->sole()->periodStart, $annual->sole()->periodEnd]);
    }

    public function test_a_lease_that_ends_the_day_before_its_anniversary_is_billed_the_same_way(): void
    {
        $this->lease('m', 'monthly', '2026-11-12', '2027-11-11');

        for ($day = Carbon::parse('2026-11-01'); $day->lt('2027-11-30'); $day->addDays(10)) {
            $this->generate($day->toDateString());
        }

        $this->assertCount(12, $this->invoices('m'));
        $this->assertSame('2027-11-11', $this->invoices('m')->last()->periodEnd);
    }

    public function test_periods_follow_the_lease_start_date_and_leave_no_gaps_even_from_a_month_end(): void
    {
        $this->lease('l1', 'monthly', '2027-01-31', '2028-01-30');

        for ($day = Carbon::parse('2027-01-10'); $day->lt('2028-01-20'); $day->addDays(10)) {
            $this->generate($day->toDateString());
        }

        $periods = $this->invoices('l1');
        $this->assertCount(12, $periods);
        $this->assertSame('2027-01-31', $periods->first()->periodStart);
        $this->assertSame('2027-02-28', $periods[1]->periodStart);
        $this->assertSame('2027-03-31', $periods[2]->periodStart, 'back on the 31st: no drift');
        foreach ($periods as $i => $p) {
            if ($i > 0) {
                $this->assertSame(Carbon::parse($periods[$i - 1]->periodEnd)->addDay()->toDateString(), $p->periodStart, "no gap or overlap before period {$i}");
            }
        }
        $this->assertSame('2028-01-30', $periods->last()->periodEnd);
    }

    // ---- Never twice ----------------------------------------------------------------------

    public function test_running_again_does_not_invoice_a_period_twice(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');

        $first = $this->generate();
        $second = $this->generate();
        $later = $this->generate('2026-11-16');

        $this->assertSame([1, 0, 0], [$first['created'], $second['created'], $later['created']]);
        $this->assertSame([0, 1, 1], [$first['skipped'], $second['skipped'], $later['skipped']]);
        $this->assertCount(1, $this->invoices('l1'));
    }

    public function test_the_database_refuses_a_second_invoice_for_the_same_lease_and_period(): void
    {
        $lease = $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');
        $service = app(InvoiceGenerationService::class);
        $period = $service->periodsDue($lease, Carbon::today())[0];

        $this->assertNotNull($service->issue($lease, $period, Carbon::today()));
        $this->assertNull($service->issue($lease, $period, Carbon::today()), 'a second attempt (say, a parallel run) quietly does nothing');
        $this->assertCount(1, $this->invoices('l1'));
    }

    public function test_an_invoice_raised_by_hand_for_the_period_is_respected(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');
        $this->w->invoice(['id' => 'inv_manual', 'leaseId' => 'l1', 'unitId' => 'u_l1', 'dueDate' => '2026-11-20', 'issueDate' => '2026-11-06']);

        $result = $this->generate();

        $this->assertSame([0, 1], [$result['created'], $result['skipped']]);
        $this->assertCount(1, $this->invoices('l1'));
    }

    public function test_a_hand_made_invoice_for_an_earlier_period_does_not_stand_in_for_this_one(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');
        $this->w->invoice(['id' => 'inv_oct', 'leaseId' => 'l1', 'unitId' => 'u_l1', 'dueDate' => '2026-10-01', 'issueDate' => '2026-09-17']);

        $this->assertSame(1, $this->generate()['created']);
    }

    public function test_a_cancelled_hand_made_invoice_does_not_count_as_billing_the_period(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');
        $this->w->invoice(['id' => 'inv_void', 'leaseId' => 'l1', 'unitId' => 'u_l1', 'dueDate' => '2026-11-20', 'status' => 'cancelled']);

        $this->assertSame(1, $this->generate()['created']);
    }

    public function test_cancelling_a_generated_invoice_means_do_not_bill_that_period_again(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');
        $this->generate();
        $invoice = $this->invoices('l1')->sole();

        $this->as($this->w->managerA)->postJson("/api/v1/invoices/{$invoice->id}/cancel")->assertOk();
        $result = $this->generate();

        $this->assertSame([0, 1], [$result['created'], $result['skipped']]);
        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    // ---- Which leases are billed -------------------------------------------------------------

    public function test_only_current_leases_are_billed(): void
    {
        foreach (['active', 'expiring', 'renewed'] as $status) {
            $this->lease("l_{$status}", 'monthly', '2026-06-01', '2027-05-31', ['status' => $status]);
        }
        foreach (['terminated', 'expired'] as $status) {
            $this->lease("l_{$status}", 'monthly', '2026-06-01', '2027-05-31', ['status' => $status]);
        }

        $this->generate();

        foreach (['active', 'expiring', 'renewed'] as $status) {
            $this->assertCount(1, $this->invoices("l_{$status}"), $status);
        }
        foreach (['terminated', 'expired'] as $status) {
            $this->assertCount(0, $this->invoices("l_{$status}"), $status);
        }
    }

    public function test_leases_that_cannot_be_billed_automatically_are_counted_not_guessed_at(): void
    {
        $this->lease('custom', 'custom', '2026-06-01', '2027-05-31');
        $this->lease('nobody', 'monthly', '2026-06-01', '2027-05-31', ['tenantId' => null]);
        $this->lease('free', 'monthly', '2026-06-01', '2027-05-31', ['rent' => 0]);
        $this->lease('fine', 'monthly', '2026-06-01', '2027-05-31');

        $result = $this->generate();

        $this->assertSame([1, 3], [$result['created'], $result['unbillable']]);
        $this->assertSame(1, Invoice::query()->count());
    }

    // ---- What an issued invoice does ---------------------------------------------------------

    public function test_the_tenant_is_told_and_the_issue_is_audited_as_the_system(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');

        $this->generate();
        $inv = $this->invoices('l1')->sole();

        $note = Notification::query()->where('forUserId', 'ten_ada')->where('title', 'New invoice issued')->sole();
        $this->assertStringContainsString($inv->code, $note->body);
        $this->assertStringContainsString('₦110,000', $note->body);
        $this->assertStringContainsString('is due 15 Nov 2026.', $note->body);          // not "2026-11-15"
        $this->assertSame("/portal/bills/{$inv->id}", $note->link);
        $entry = AuditLog::query()->where('entityId', $inv->id)->sole();
        $this->assertSame(['system', 'create', 'prop_a'], [$entry->userId, $entry->action, $entry->propertyId]);
    }

    public function test_a_generated_invoice_shows_up_for_the_right_people_only(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');
        $this->generate();
        $id = $this->invoices('l1')->sole()->id;

        $ids = fn ($who) => collect($this->as($this->w->{$who})->getJson('/api/v1/invoices?per_page=100')->assertOk()->json('data'))->pluck('id')->all();

        foreach (['tenantUser', 'managerA', 'financeA', 'admin'] as $who) {
            $this->assertContains($id, $ids($who), $who);
        }
        $this->assertNotContains($id, $ids('ownerB'));
    }

    public function test_an_unpaid_generated_invoice_goes_overdue_after_its_due_day_like_any_other(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');
        $this->generate('2026-11-17');                                           // November (due the 15th... issued today) and December

        app(StatusRefreshService::class)->refresh(false, Carbon::parse('2026-12-02'));

        $this->assertSame(['overdue', 'overdue'], $this->invoices('l1')->pluck('status')->all());
    }

    // ---- The command -----------------------------------------------------------------------------

    public function test_a_dry_run_reports_without_issuing(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');

        $result = $this->generate(null, true);

        $this->assertSame(1, $result['created']);
        $this->assertStringContainsString('would invoice 2026-11-01', $result['log'][0]);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, Notification::query()->where('title', 'New invoice issued')->count());
    }

    public function test_the_command_reports_what_it_did_and_is_safe_to_rerun(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');
        $this->lease('custom', 'custom', '2026-06-01', '2027-05-31');

        $this->artisan('pms:generate-invoices')
            ->expectsOutputToContain('Invoices: 1 issued, 0 already invoiced. 1 lease(s) can\'t be billed automatically')
            ->assertSuccessful();
        $this->artisan('pms:generate-invoices')
            ->expectsOutputToContain('Invoices: 0 issued, 1 already invoiced.')
            ->assertSuccessful();
    }

    public function test_the_command_can_be_pointed_at_another_day_but_not_in_production(): void
    {
        $this->lease('l1', 'monthly', '2026-12-01', '2027-11-30');

        $this->artisan('pms:generate-invoices', ['--date' => '2026-11-17'])->expectsOutputToContain('1 issued')->assertSuccessful();

        $this->app['env'] = 'production';
        $this->artisan('pms:generate-invoices', ['--date' => '2026-11-17'])->assertFailed();
    }

    public function test_a_dry_run_from_the_command_changes_nothing(): void
    {
        $this->lease('l1', 'monthly', '2026-06-01', '2027-05-31');

        $this->artisan('pms:generate-invoices', ['--dry-run' => true])->expectsOutputToContain('[dry run] Invoices: 1 to issue')->assertSuccessful();

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_it_is_scheduled_daily_before_the_status_refresh_and_the_reminders(): void
    {
        $events = collect(app(Schedule::class)->events());
        $time = fn (string $needle) => $events->first(fn ($e) => str_contains($e->command, $needle))?->expression;

        $this->assertSame('0 6 * * *', $time('pms:generate-invoices'));
        $this->assertSame('30 6 * * *', $time('pms:refresh-statuses'));
        $this->assertSame('0 7 * * *', $time('pms:rent-reminders'));
    }
}

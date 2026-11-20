<?php

namespace Tests\Feature\AutoDebit;

use App\Mail\AutoDebitMail;
use App\Models\AutoDebitAttempt;
use App\Models\AutoDebitMandate;
use App\Models\Payment;
use App\Services\AutoDebitService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use ReflectionMethod;
use Tests\Support\FakePaystack;

class ChargingScheduleTest extends AutoDebitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mandate();
    }

    // ---- When a first charge happens ---------------------------------------

    public function test_an_invoice_due_today_is_charged_in_full_and_settled(): void
    {
        $this->dueOn('2026-11-11');

        $stats = $this->runOn('2026-11-11');

        $this->assertSame(1, $stats['succeeded']);
        $charge = $this->chargeCalls();
        $this->assertCount(1, $charge);
        $this->assertSame(10_000_000, $charge[0]['data']['amount']);             // ₦100,000 in kobo
        $this->assertSame(self::CARD_CODE, $charge[0]['data']['authorization_code']);
        $this->assertSame('ada@tenant.test', $charge[0]['data']['email']);

        $invoice = $this->w->invoice->fresh();
        $this->assertSame(100_000, (int) $invoice->amountPaid);
        $this->assertSame('fully-paid', $invoice->status);
        $this->assertSame('approved', Payment::query()->where('invoiceId', $invoice->id)->value('status'));
        $this->assertSame('success', $this->attempts()->first()->status);
        $this->assertContains('Rent paid automatically', $this->tenantNotifications());
    }

    public function test_an_invoice_not_yet_due_is_left_alone(): void
    {
        $this->dueOn('2026-11-12');

        $stats = $this->runOn('2026-11-11');

        $this->assertSame(0, $stats['charged']);
        $this->assertCount(0, $this->chargeCalls());
        $this->assertCount(0, $this->attempts());
    }

    public function test_arrears_from_before_the_card_was_saved_are_never_charged(): void
    {
        $this->dueOn('2026-09-30');   // card enrolled 2026-10-01

        $this->runOn('2026-10-01');
        $this->runOn('2026-10-02');

        $this->assertCount(0, $this->chargeCalls());
        $this->assertCount(0, $this->attempts());
    }

    public function test_a_missed_job_does_not_chase_an_invoice_that_is_far_past_due(): void
    {
        $this->dueOn('2026-11-07');

        $this->runOn('2026-11-11');   // 4 days late

        $this->assertCount(0, $this->chargeCalls());
    }

    public function test_a_job_that_ran_a_few_days_late_still_makes_the_first_attempt(): void
    {
        $this->dueOn('2026-11-08');

        $this->runOn('2026-11-11');   // 3 days late is within grace

        $this->assertCount(1, $this->chargeCalls());
    }

    public function test_a_second_run_on_the_same_day_does_not_charge_twice(): void
    {
        $this->dueOn('2026-11-11');

        $this->runOn('2026-11-11');
        $this->runOn('2026-11-11');

        $this->assertCount(1, $this->chargeCalls());
        $this->assertCount(1, $this->attempts());
    }

    public function test_only_the_outstanding_balance_is_charged(): void
    {
        $this->dueOn('2026-11-11', ['amountPaid' => 40_000, 'status' => 'partially-paid']);

        $this->runOn('2026-11-11');

        $this->assertSame(6_000_000, $this->chargeCalls()[0]['data']['amount']);
        $this->assertSame(100_000, (int) $this->w->invoice->fresh()->amountPaid);
    }

    public function test_settled_and_cancelled_invoices_are_never_charged(): void
    {
        $this->dueOn('2026-11-11', ['amountPaid' => 100_000, 'status' => 'fully-paid']);
        $this->w->invoice(['id' => 'inv_cancelled', 'dueDate' => '2026-11-11', 'status' => 'cancelled']);

        $this->runOn('2026-11-11');

        $this->assertCount(0, $this->chargeCalls());
    }

    public function test_an_invoice_someone_is_paying_by_hand_is_not_auto_charged(): void
    {
        $this->dueOn('2026-11-11');
        $this->as($this->w->tenantUser)->postJson('/api/v1/payments/submit', [
            'invoiceId' => $this->w->invoice->id, 'type' => 'rent', 'method' => 'cash', 'amount' => 100_000,
            'paymentDate' => '2026-11-11', 'reference' => 'HAND',
        ])->assertCreated();

        $this->runOn('2026-11-11');

        $this->assertCount(0, $this->chargeCalls());
    }

    public function test_only_the_enrolled_tenants_invoices_are_charged(): void
    {
        $this->w->invoice(['id' => 'inv_bob', 'tenantId' => 'ten_bob', 'dueDate' => '2026-11-11']);
        $this->dueOn('2026-11-11');

        $this->runOn('2026-11-11');

        $this->assertCount(1, $this->chargeCalls());
        $this->assertCount(0, AutoDebitAttempt::query()->where('invoiceId', 'inv_bob')->get());
    }

    public function test_cancelled_mandates_are_ignored(): void
    {
        AutoDebitMandate::query()->update(['status' => 'cancelled']);
        $this->dueOn('2026-11-11');

        $stats = $this->runOn('2026-11-11');

        $this->assertSame(0, $stats['mandates']);
        $this->assertCount(0, $this->chargeCalls());
    }

    public function test_a_dry_run_charges_nothing_and_records_nothing(): void
    {
        $this->dueOn('2026-11-11');

        $stats = $this->runOn('2026-11-11', ['dryRun' => true]);

        $this->assertSame(1, $stats['charged']);
        $this->assertStringContainsString('would charge', $stats['log'][0]);
        $this->assertCount(0, $this->chargeCalls());
        $this->assertCount(0, $this->attempts());
        $this->assertSame(0, Payment::query()->count());
    }

    // ---- Retries ------------------------------------------------------------

    public function test_failed_charges_are_retried_on_due_plus_one_and_due_plus_three_then_stop(): void
    {
        $this->paystack->defaultCharge = 'declined';
        $this->dueOn('2026-11-11');

        $this->runOn('2026-11-11');
        $this->assertSame('2026-11-12', $this->attempts()->last()->nextAttemptOn);
        $this->assertCount(1, $this->chargeCalls());

        $this->runOn('2026-11-11');                       // same day: nothing
        $this->assertCount(1, $this->chargeCalls());

        $this->runOn('2026-11-12');                       // due+1: attempt 2
        $this->assertCount(2, $this->chargeCalls());
        $this->assertSame('2026-11-14', $this->attempts()->last()->nextAttemptOn);

        $this->runOn('2026-11-13');                       // due+2: nothing
        $this->assertCount(2, $this->chargeCalls());

        $stats = $this->runOn('2026-11-14');              // due+3: attempt 3, the last
        $this->assertCount(3, $this->chargeCalls());
        $this->assertSame(1, $stats['finalFailures']);
        $this->assertNull($this->attempts()->last()->nextAttemptOn);

        foreach (['2026-11-15', '2026-11-18', '2026-12-25'] as $later) {
            $this->runOn($later);
        }
        $this->assertCount(3, $this->chargeCalls(), 'there is never a fourth attempt');
        $this->assertSame(0, (int) $this->w->invoice->fresh()->amountPaid);
        $this->assertSame(['failed', 'failed', 'failed'], $this->attempts()->pluck('status')->all());
    }

    public function test_each_failure_tells_the_tenant_once_with_the_reason(): void
    {
        $this->paystack->defaultCharge = 'declined';
        $this->dueOn('2026-11-11');

        $this->runOn('2026-11-11');
        $this->runOn('2026-11-11');

        $this->assertSame(1, $this->tenantNotifications()->filter(fn ($t) => $t === 'Automatic payment failed')->count());
        Mail::assertQueued(AutoDebitMail::class, 1);
        $this->assertSame('Insufficient Funds', $this->attempts()->first()->reason);
    }

    public function test_a_retry_that_succeeds_settles_the_invoice_and_ends_the_attempts(): void
    {
        $this->paystack->charges('declined', 'success');
        $this->dueOn('2026-11-11');

        $this->runOn('2026-11-11');
        $this->runOn('2026-11-12');
        $this->runOn('2026-11-14');

        $this->assertCount(2, $this->chargeCalls());
        $this->assertSame(['failed', 'success'], $this->attempts()->pluck('status')->all());
        $this->assertSame('fully-paid', $this->w->invoice->fresh()->status);
    }

    public function test_after_three_failures_the_tenant_and_managers_are_told_and_the_invoice_goes_manual(): void
    {
        $this->paystack->defaultCharge = 'declined';
        $this->dueOn('2026-11-11');

        foreach (['2026-11-11', '2026-11-12', '2026-11-14'] as $day) {
            $this->runOn($day);
        }

        $this->assertContains('Automatic payment failed — please pay manually', $this->tenantNotifications());
        foreach (['ownerA', 'managerA'] as $who) {
            $this->assertDatabaseHas('notifications', ['forUserId' => $this->w->{$who}->id, 'title' => 'Auto-debit failed']);
        }
        $this->assertDatabaseMissing('notifications', ['forUserId' => $this->w->ownerB->id, 'title' => 'Auto-debit failed']);
        Mail::assertQueued(AutoDebitMail::class, fn (AutoDebitMail $m) => $m->hasTo($this->w->managerA->email));

        // the card is still on for future invoices
        $this->assertSame('active', AutoDebitMandate::query()->value('status'));
    }

    // ---- Uncertain outcomes -------------------------------------------------

    public function test_a_timeout_is_not_a_failure_and_is_never_charged_again(): void
    {
        $this->paystack->charges('timeout');
        $this->dueOn('2026-11-11');

        $stats = $this->runOn('2026-11-11');

        $this->assertSame(1, $stats['pending']);
        $this->assertSame('pending', $this->attempts()->first()->status);
        $this->assertSame(0, (int) $this->w->invoice->fresh()->amountPaid);

        // The money may have moved: days pass and nothing else is ever charged.
        $this->runOn('2026-11-12');
        $this->runOn('2026-11-14');
        $this->assertCount(1, $this->chargeCalls());
    }

    public function test_a_pending_charge_is_reconciled_once_paystack_confirms_it(): void
    {
        $this->paystack->charges('timeout');
        $this->dueOn('2026-11-11');
        $this->runOn('2026-11-11');
        $ref = Payment::query()->first()->gatewayReference;

        // 31 minutes later Paystack says it went through.
        $this->paystack->transaction($ref, FakePaystack::cardTransaction($ref, 10_000_000));
        Carbon::setTestNow('2026-11-11 08:01:00');
        $stats = app(AutoDebitService::class)->run(['date' => Carbon::parse('2026-11-11')]);

        $this->assertSame(1, $stats['reconciled']);
        $this->assertSame('success', $this->attempts()->first()->status);
        $this->assertSame('fully-paid', $this->w->invoice->fresh()->status);
        $this->assertCount(1, $this->chargeCalls());
    }

    public function test_a_pending_charge_that_actually_failed_is_failed_and_retried_later(): void
    {
        $this->paystack->charges('timeout');
        $this->dueOn('2026-11-11');
        $this->runOn('2026-11-11');
        $ref = Payment::query()->first()->gatewayReference;

        $this->paystack->transaction($ref, ['status' => 'failed', 'gateway_response' => 'Do not honour']);
        Carbon::setTestNow('2026-11-11 08:01:00');
        app(AutoDebitService::class)->run(['date' => Carbon::parse('2026-11-11')]);

        $this->assertSame('failed', $this->attempts()->first()->status);
        $this->assertSame('2026-11-12', $this->attempts()->first()->nextAttemptOn);
    }

    public function test_a_charge_still_unknown_to_paystack_stays_pending(): void
    {
        $this->paystack->charges('timeout');
        $this->dueOn('2026-11-11');
        $this->runOn('2026-11-11');

        Carbon::setTestNow('2026-11-11 08:01:00');
        $stats = app(AutoDebitService::class)->run(['date' => Carbon::parse('2026-11-11')]);

        $this->assertSame(0, $stats['reconciled']);
        $this->assertSame('pending', $this->attempts()->first()->status);
    }

    public function test_a_charge_paystack_reports_as_pending_waits_for_confirmation(): void
    {
        $this->paystack->charges('pending');
        $this->dueOn('2026-11-11');

        $this->runOn('2026-11-11');

        $this->assertSame('pending', $this->attempts()->first()->status);
        $this->assertSame(0, (int) $this->w->invoice->fresh()->amountPaid);
    }

    public function test_a_revoked_card_fails_the_attempt_and_stops_the_card_being_used(): void
    {
        $this->paystack->charges('revoked');
        $this->dueOn('2026-11-11');

        $this->runOn('2026-11-11');

        $this->assertSame('failed', $this->attempts()->first()->status);
        $this->assertSame('expired', AutoDebitMandate::query()->value('status'));
    }

    public function test_the_unique_attempt_index_blocks_a_double_charge_even_if_two_runs_race(): void
    {
        $invoice = $this->dueOn('2026-11-11');
        AutoDebitAttempt::query()->create([
            'id' => 'adt_other_run', 'invoiceId' => $invoice->id, 'tenantId' => 'ten_ada', 'attemptNo' => 1,
            'status' => 'pending', 'amount' => 100_000, 'attemptedAt' => now()->toIso8601String(),
        ]);

        // Simulate the second process arriving at the charge step having also decided "attempt 1".
        $charge = new ReflectionMethod(AutoDebitService::class, 'charge');
        // Postgres aborts the surrounding transaction after a caught unique violation (the test runner wraps each
        // test in one); a savepoint keeps that contained, as it never is in the real job.
        DB::beginTransaction();
        $result = $charge->invoke(app(AutoDebitService::class), $invoice, AutoDebitMandate::query()->first(), 1, 100_000, Carbon::now());

        DB::rollBack();

        $this->assertNull($result);
        $this->assertCount(0, $this->chargeCalls());
        $this->assertSame(0, Payment::query()->count());
    }

    // ---- Reminders & expiry -------------------------------------------------

    public function test_the_tenant_is_reminded_the_day_before_exactly_once(): void
    {
        $this->dueOn('2026-11-12');

        $first = $this->runOn('2026-11-11');
        $second = $this->runOn('2026-11-11');

        $this->assertSame(1, $first['reminded']);
        $this->assertSame(0, $second['reminded']);
        $this->assertSame(1, $this->tenantNotifications()->filter(fn ($t) => $t === 'Automatic payment tomorrow')->count());
        Mail::assertQueued(AutoDebitMail::class, fn (AutoDebitMail $m) => $m->hasTo('ada@tenant.test'));
        $this->assertCount(0, $this->chargeCalls());
    }

    public function test_no_reminder_for_invoices_due_before_enrolment_or_two_days_out(): void
    {
        $this->dueOn('2026-11-13');
        $this->runOn('2026-11-11');
        $this->assertCount(0, $this->tenantNotifications()->filter(fn ($t) => $t === 'Automatic payment tomorrow'));

        AutoDebitMandate::query()->update(['enrolledAt' => '2026-11-13T09:00:00+00:00']);
        $this->dueOn('2026-11-12');
        $this->runOn('2026-11-11');
        $this->assertCount(0, $this->tenantNotifications()->filter(fn ($t) => $t === 'Automatic payment tomorrow'));
    }

    public function test_an_expired_card_is_switched_off_and_the_tenant_asked_for_a_new_one(): void
    {
        AutoDebitMandate::query()->update(['expMonth' => '10', 'expYear' => '2026']);
        $this->dueOn('2026-11-11');

        $stats = $this->runOn('2026-11-11');

        $this->assertSame(1, $stats['expired']);
        $this->assertSame('expired', AutoDebitMandate::query()->value('status'));
        $this->assertCount(0, $this->chargeCalls());
        $this->assertCount(1, $this->paystack->to('/customer/deactivate_authorization'));
        $this->assertContains('Your auto-debit card has expired', $this->tenantNotifications());
        $this->as($this->w->tenantUser)->getJson('/api/v1/auto-debit')->assertJsonPath('data.status', 'expired');
    }

    public function test_a_card_is_valid_through_the_end_of_its_expiry_month(): void
    {
        AutoDebitMandate::query()->update(['expMonth' => '11', 'expYear' => '2026']);
        $this->dueOn('2026-11-30');

        $stats = $this->runOn('2026-11-30');

        $this->assertSame(0, $stats['expired']);
        $this->assertCount(1, $this->chargeCalls());
    }
}

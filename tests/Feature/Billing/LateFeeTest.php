<?php

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\AutoDebitAttempt;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Services\LateFeeService;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\PmsTestCase;

/** Late fees: one fee, on what is still owed, once the grace period has passed; waivable, never doubled. */
class LateFeeTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 09:00:00');
        Invoice::query()->delete();
        app(SettingsService::class)->put('organization', ['lateFeePercent' => 10, 'gracePeriodDays' => 5]);
    }

    private function due(string $date, array $over = []): Invoice
    {
        return $this->w->invoice($over + ['dueDate' => $date, 'status' => 'overdue']);
    }

    private function charge(?string $date = null, bool $dry = false): array
    {
        return app(LateFeeService::class)->run($dry, $date ? Carbon::parse($date) : null);
    }

    public function test_an_invoice_past_its_grace_period_is_charged_a_percentage_of_what_is_owed(): void
    {
        $inv = $this->due('2026-11-10', ['amountPaid' => 40_000, 'status' => 'partially-paid']);   // owes 60,000

        $r = $this->charge();

        $this->assertSame(1, $r['applied']);
        $inv->refresh();
        $this->assertSame(6_000, $inv->penalty);
        $this->assertSame(106_000, $inv->total);
        $this->assertSame('2026-11-20', $inv->lateFeeAppliedOn);
        $this->assertSame('partially-paid', $inv->status);
    }

    public function test_nothing_is_charged_until_the_grace_days_have_fully_passed(): void
    {
        $inv = $this->due('2026-11-15');      // grace 5 → first chargeable on the 21st

        $this->assertSame(0, $this->charge('2026-11-20')['applied']);
        $this->assertSame(1, $this->charge('2026-11-21')['applied']);
        $this->assertSame(10_000, $inv->refresh()->penalty);
    }

    public function test_the_fee_is_charged_once_only(): void
    {
        $inv = $this->due('2026-11-01');

        $this->charge();
        $this->charge('2026-12-20');

        $this->assertSame(10_000, $inv->refresh()->penalty);
        $this->assertSame(110_000, $inv->total);
    }

    public function test_settings_drive_the_rate_and_the_grace(): void
    {
        app(SettingsService::class)->put('organization', ['lateFeePercent' => 2.5, 'gracePeriodDays' => 0]);
        $inv = $this->due('2026-11-19');

        $this->charge();

        $this->assertSame(2_500, $inv->refresh()->penalty);
    }

    public function test_a_zero_percent_setting_turns_late_fees_off(): void
    {
        app(SettingsService::class)->put('organization', ['lateFeePercent' => 0]);
        $inv = $this->due('2026-11-01');

        $this->assertSame(0, $this->charge()['applied']);
        $this->assertSame(0, (int) $inv->refresh()->penalty);
    }

    public function test_paid_cancelled_and_not_yet_due_invoices_are_left_alone(): void
    {
        $paid = $this->due('2026-11-01', ['amountPaid' => 100_000, 'status' => 'fully-paid']);
        $cancelled = $this->due('2026-11-01', ['status' => 'cancelled']);
        $notDue = $this->w->invoice(['dueDate' => '2026-11-25', 'status' => 'unpaid']);

        $this->assertSame(0, $this->charge()['applied']);
        foreach ([$paid, $cancelled, $notDue] as $i) {
            $this->assertSame(0, (int) $i->refresh()->penalty);
        }
    }

    public function test_an_invoice_that_already_carries_a_penalty_is_not_charged_again(): void
    {
        $inv = $this->due('2026-11-01', ['penalty' => 5_000, 'total' => 105_000]);

        $this->assertSame(0, $this->charge()['applied']);
        $this->assertSame(105_000, $inv->refresh()->total);
    }

    public function test_no_fee_while_a_payment_is_waiting_to_be_verified_or_a_debit_is_pending(): void
    {
        $a = $this->due('2026-11-01');
        $b = $this->due('2026-11-01');
        Payment::query()->create(['id' => 'pay_w', 'code' => 'PYW', 'invoiceId' => $a->id, 'leaseId' => 'lease_a', 'unitId' => 'unit_a', 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada', 'type' => 'rent', 'method' => 'transfer', 'amount' => 100_000, 'paymentDate' => '2026-11-19', 'reference' => 'w', 'status' => 'pending-verification']);
        AutoDebitAttempt::query()->create(['id' => 'adt_w', 'invoiceId' => $b->id, 'tenantId' => 'ten_ada', 'attemptNo' => 1, 'status' => 'pending', 'amount' => 100_000, 'attemptedAt' => now()->toIso8601String()]);

        $this->assertSame(0, $this->charge()['applied']);

        Payment::query()->where('id', 'pay_w')->update(['status' => 'rejected']);
        $this->assertSame(1, $this->charge()['applied']);
        $this->assertSame(10_000, $a->refresh()->penalty);
    }

    public function test_charging_tells_the_tenant_and_is_audited(): void
    {
        $inv = $this->due('2026-11-01');

        $this->charge();

        $note = Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Late fee charged')->first();
        $this->assertNotNull($note);
        $this->assertSame("/portal/bills/{$inv->id}", $note->link);
        $this->assertTrue(AuditLog::query()->where('entityId', $inv->id)->where('summary', 'like', 'Late fee%')->exists());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $inv = $this->due('2026-11-01');

        $r = $this->charge(null, true);

        $this->assertSame(1, $r['applied']);
        $this->assertSame(10_000, $r['total']);
        $this->assertSame(0, (int) $inv->refresh()->penalty);
        $this->assertNull($inv->lateFeeAppliedOn);
    }

    public function test_the_command_runs_and_refuses_the_date_switch_in_production(): void
    {
        $inv = $this->due('2026-11-01');

        $this->artisan('pms:apply-late-fees')->assertSuccessful();
        $this->assertSame(10_000, $inv->refresh()->penalty);

        $this->app['env'] = 'production';
        $this->artisan('pms:apply-late-fees', ['--date' => '2027-01-01'])->assertFailed();
    }

    public function test_a_payment_after_the_fee_must_cover_it_to_settle_the_invoice(): void
    {
        $inv = $this->due('2026-11-01');
        $this->charge();
        $inv->refresh();
        $inv->update(['amountPaid' => 100_000]);
        app(InvoiceService::class)->recompute($inv);

        $this->assertSame($inv->status === 'overdue' ? 'overdue' : $inv->status, $inv->status);
        $this->assertNotSame('fully-paid', $inv->status);       // 100,000 no longer covers the 110,000
    }

    // ---- waiving ----------------------------------------------------------------------

    public function test_a_manager_can_waive_the_fee_and_it_is_not_charged_again(): void
    {
        $inv = $this->due('2026-11-01');
        $this->charge();

        $this->as($this->w->managerA)->postJson("/api/v1/invoices/{$inv->id}/waive-late-fee", ['reason' => 'Bank delay'])
            ->assertOk()->assertJsonPath('data.penalty', 0)->assertJsonPath('data.total', 100_000);

        $this->assertSame('2026-11-20', $inv->refresh()->lateFeeWaivedOn);
        $this->assertSame(0, $this->charge('2026-12-30')['applied']);
        $this->assertTrue(AuditLog::query()->where('entityId', $inv->id)->where('summary', 'like', '%Bank delay%')->exists());
        $note = Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Late fee waived')->first();
        $this->assertNotNull($note);
        $this->assertSame("/portal/bills/{$inv->id}", $note->link);
    }

    public function test_waiving_can_settle_an_invoice_that_was_paid_in_full_before_the_fee(): void
    {
        $inv = $this->due('2026-11-01', ['amountPaid' => 100_000, 'status' => 'overdue']);
        $inv->forceFill(['penalty' => 10_000, 'total' => 110_000, 'lateFeeAppliedOn' => '2026-11-10'])->save();

        $this->as($this->w->financeA)->postJson("/api/v1/invoices/{$inv->id}/waive-late-fee", ['reason' => 'Goodwill'])->assertOk()
            ->assertJsonPath('data.status', 'fully-paid');
    }

    public function test_waiving_needs_a_reason_and_a_fee_and_the_right_role(): void
    {
        $inv = $this->due('2026-11-01');
        $this->as($this->w->managerA)->postJson("/api/v1/invoices/{$inv->id}/waive-late-fee", ['reason' => 'x'])->assertUnprocessable();
        $this->postJson("/api/v1/invoices/{$inv->id}/waive-late-fee", ['reason' => 'Fine'])->assertUnprocessable();   // no fee yet

        $this->charge();
        $this->as($this->w->tenantUser)->postJson("/api/v1/invoices/{$inv->id}/waive-late-fee", ['reason' => 'Please'])->assertForbidden();
        $this->as($this->w->ownerB)->postJson("/api/v1/invoices/{$inv->id}/waive-late-fee", ['reason' => 'Please'])->assertForbidden();
        $this->assertSame(10_000, $inv->refresh()->penalty);
    }

    public function test_the_penalty_cannot_be_set_through_the_ordinary_invoice_endpoints(): void
    {
        $inv = $this->due('2026-11-01');

        $this->as($this->w->managerA)->patchJson("/api/v1/invoices/{$inv->id}", ['penalty' => 99_000, 'discount' => 0])->assertOk();

        $this->assertSame(0, (int) $inv->refresh()->penalty);
    }

    public function test_editing_line_items_keeps_the_fee_in_the_total(): void
    {
        $inv = $this->due('2026-11-01');
        $this->charge();

        $this->as($this->w->managerA)->patchJson("/api/v1/invoices/{$inv->id}", ['lineItems' => [['description' => 'Rent', 'type' => 'rent', 'amount' => 200_000]]])->assertOk();

        $this->assertSame(210_000, $inv->refresh()->total);
    }

    public function test_the_fee_is_charged_on_what_is_owed_after_a_discount(): void
    {
        $inv = $this->due('2026-11-01', ['discount' => 10_000, 'total' => 90_000]);

        $this->charge();

        $inv->refresh();
        $this->assertSame(9_000, $inv->penalty);
        $this->assertSame(99_000, $inv->total);          // 100,000 − 10,000 + 9,000
    }

    public function test_a_fee_waived_on_an_invoice_that_already_carried_one_is_never_charged_back(): void
    {
        $inv = $this->due('2026-11-01', ['penalty' => 5_000, 'total' => 105_000]);

        $this->as($this->w->managerA)->postJson("/api/v1/invoices/{$inv->id}/waive-late-fee", ['reason' => 'Goodwill'])->assertOk();

        $this->assertSame(0, $this->charge('2026-12-30')['applied']);
        $this->assertSame(100_000, $inv->refresh()->total);
    }

    // ---- the settings are read once per run, not once per invoice -----------------------------------------------

    private function settingsReads(callable $do): int
    {
        $reads = 0;
        DB::listen(function ($q) use (&$reads) {
            if (preg_match('/from ["`]?settings["`]?\b/i', $q->sql)) {
                $reads++;
            }
        });
        $do();

        return $reads;
    }

    public function test_a_run_reads_the_late_fee_settings_once_however_many_invoices_it_charges(): void
    {
        foreach (range(1, 8) as $i) {
            $this->due('2026-11-01', ['id' => "inv_late_{$i}"]);
        }

        $reads = $this->settingsReads(function () use (&$report) {
            $report = $this->charge();
        });

        $this->assertSame(8, $report['applied']);
        $this->assertSame(1, $reads);
    }

    public function test_a_run_that_charges_nothing_still_reads_the_settings_once(): void
    {
        foreach (range(1, 5) as $i) {
            $this->due('2026-11-19', ['id' => "inv_fresh_{$i}"]);                   // inside the grace period
        }

        $reads = $this->settingsReads(fn () => $this->charge());

        $this->assertSame(1, $reads);
    }

    public function test_a_change_to_the_settings_is_picked_up_by_the_next_run(): void
    {
        $inv = $this->due('2026-11-01');
        $this->charge(null, true);

        app(SettingsService::class)->put('organization', ['lateFeePercent' => 2.5, 'gracePeriodDays' => 0]);
        $this->charge();

        $this->assertSame(2_500, $inv->refresh()->penalty);                         // 2.5% of 100,000, not the 10% of the earlier run
    }
}

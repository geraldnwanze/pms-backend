<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\User;
use Tests\PmsTestCase;
use Tests\Support\World;

/** Manual (bank transfer / cash) payments: who may submit, approve, reject and refund. */
class PaymentApprovalTest extends PmsTestCase
{
    private function submit(?User $as = null, array $overrides = []): string
    {
        $this->as($as ?? $this->w->tenantUser);

        return $this->postJson('/api/v1/payments/submit', $overrides + [
            'invoiceId' => $this->w->invoice->id, 'type' => 'rent', 'method' => 'bank-transfer',
            'amount' => 100_000, 'paymentDate' => now()->toDateString(), 'reference' => 'TRF-'.uniqid(),
        ])->assertCreated()->json('data.id');
    }

    public function test_a_submitted_payment_waits_for_verification_without_touching_the_balance(): void
    {
        $id = $this->submit();

        $this->assertSame('pending-verification', Payment::query()->find($id)->status);
        $invoice = $this->w->invoice->fresh();
        $this->assertSame(0, (int) $invoice->amountPaid);
        $this->assertSame('pending-verification', $invoice->status);
    }

    public function test_finance_and_admin_can_approve(): void
    {
        foreach (['financeA', 'admin'] as $who) {
            $invoice = $this->w->invoice(['id' => "inv_{$who}"]);
            $this->as($this->w->tenantUser);
            $id = $this->postJson('/api/v1/payments/submit', [
                'invoiceId' => $invoice->id, 'type' => 'rent', 'method' => 'cash', 'amount' => 100_000,
                'paymentDate' => now()->toDateString(), 'reference' => "REF-{$who}",
            ])->assertCreated()->json('data.id');

            $this->as($this->w->{$who})->postJson("/api/v1/payments/{$id}/approve")->assertOk();
            $this->assertSame('fully-paid', $invoice->fresh()->status);
        }
    }

    public function test_managers_owners_agents_and_tenants_cannot_approve_or_reject(): void
    {
        $id = $this->submit();

        foreach (['managerA', 'ownerA', 'tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who});
            $this->postJson("/api/v1/payments/{$id}/approve")->assertForbidden();
            $this->postJson("/api/v1/payments/{$id}/reject", ['reason' => 'no thanks'])->assertForbidden();
        }

        $this->assertSame('pending-verification', Payment::query()->find($id)->status);
        $this->assertSame(0, (int) $this->w->invoice->fresh()->amountPaid);
    }

    public function test_finance_of_another_property_cannot_approve(): void
    {
        $id = $this->submit();
        $outsider = World::user('finance', 'finance.b@pms.test');
        World::member($this->w->pB, $outsider, 'finance');

        $status = $this->as($outsider)->postJson("/api/v1/payments/{$id}/approve")->status();

        $this->assertContains($status, [403, 404]);
        $this->assertSame('pending-verification', Payment::query()->find($id)->status);
    }

    public function test_a_payment_cannot_be_approved_twice(): void
    {
        $id = $this->submit();
        $this->as($this->w->financeA)->postJson("/api/v1/payments/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/payments/{$id}/approve")->assertStatus(422);

        $this->assertSame(100_000, (int) $this->w->invoice->fresh()->amountPaid);
    }

    public function test_rejecting_requires_a_reason_and_leaves_the_invoice_unpaid(): void
    {
        $id = $this->submit();
        $this->as($this->w->financeA);

        $this->postJson("/api/v1/payments/{$id}/reject", [])->assertStatus(422);
        $this->postJson("/api/v1/payments/{$id}/reject", ['reason' => 'Receipt unreadable'])->assertOk();

        $this->assertSame('rejected', Payment::query()->find($id)->status);
        $this->assertSame(0, (int) $this->w->invoice->fresh()->amountPaid);
    }

    public function test_only_finance_or_admin_can_refund_and_the_balance_is_restored(): void
    {
        $id = $this->submit();
        $this->as($this->w->financeA)->postJson("/api/v1/payments/{$id}/approve")->assertOk();

        $this->as($this->w->managerA)->postJson("/api/v1/payments/{$id}/refund", ['reason' => 'mistake'])->assertForbidden();
        $this->as($this->w->tenantUser)->postJson("/api/v1/payments/{$id}/refund", ['reason' => 'mine'])->assertForbidden();
        $this->assertSame(100_000, (int) $this->w->invoice->fresh()->amountPaid);

        $this->as($this->w->financeA)->postJson("/api/v1/payments/{$id}/refund", ['reason' => 'Duplicate'])->assertOk();
        $this->assertSame('refunded', Payment::query()->find($id)->status);
        $this->assertSame(0, (int) $this->w->invoice->fresh()->amountPaid);
    }

    public function test_a_pending_payment_cannot_be_refunded(): void
    {
        $id = $this->submit();

        $this->as($this->w->financeA)->postJson("/api/v1/payments/{$id}/refund", ['reason' => 'too early'])->assertStatus(422);
    }

    public function test_only_one_open_manual_payment_per_invoice(): void
    {
        $this->submit();
        $this->as($this->w->tenantUser)->postJson('/api/v1/payments/submit', [
            'invoiceId' => $this->w->invoice->id, 'type' => 'rent', 'method' => 'cash', 'amount' => 1,
            'paymentDate' => now()->toDateString(), 'reference' => 'DUP',
        ])->assertStatus(409);
    }

    public function test_a_tenant_cannot_submit_a_payment_against_someone_elses_invoice(): void
    {
        $other = $this->w->invoice(['id' => 'inv_other', 'tenantId' => 'ten_someone_else']);

        $status = $this->as($this->w->tenantUser)->postJson('/api/v1/payments/submit', [
            'invoiceId' => $other->id, 'type' => 'rent', 'method' => 'cash', 'amount' => 1,
            'paymentDate' => now()->toDateString(), 'reference' => 'X',
        ])->status();

        $this->assertContains($status, [403, 404]);
        $this->assertSame(0, Payment::query()->where('invoiceId', 'inv_other')->count());
    }

    // ---- part-payments: the amount must be stored even when the invoice's status does not change ----------------

    private function pay(int $amount, string $ref): string
    {
        $this->as($this->w->tenantUser);
        $id = $this->postJson('/api/v1/payments/submit', [
            'invoiceId' => $this->w->invoice->id, 'type' => 'rent', 'method' => 'cash', 'amount' => $amount,
            'paymentDate' => now()->toDateString(), 'reference' => $ref,
        ])->assertCreated()->json('data.id');
        $this->as($this->w->financeA)->postJson("/api/v1/payments/{$id}/approve")->assertOk();

        return $id;
    }

    public function test_a_second_part_payment_is_added_to_the_first(): void
    {
        $this->assertSame(100_000, (int) $this->w->invoice->total);

        $this->pay(30_000, 'P1');
        $this->assertSame([30_000, 'partially-paid'], [(int) $this->w->invoice->fresh()->amountPaid, $this->w->invoice->fresh()->status]);

        $second = $this->pay(20_000, 'P2');                                     // the status stays "partially-paid"; the money must still land

        $invoice = $this->w->invoice->fresh();
        $this->assertSame(50_000, (int) $invoice->amountPaid);
        $this->assertSame('partially-paid', $invoice->status);
        $this->assertSame(50_000, (int) Payment::query()->find($second)->balanceAfter);        // what is left, as the receipt shows it
    }

    public function test_part_payments_that_add_up_to_the_total_settle_the_invoice(): void
    {
        $this->pay(60_000, 'P1');
        $this->pay(30_000, 'P2');
        $this->pay(10_000, 'P3');

        $invoice = $this->w->invoice->fresh();
        $this->assertSame(100_000, (int) $invoice->amountPaid);
        $this->assertSame('fully-paid', $invoice->status);
    }

    public function test_refunding_one_of_several_part_payments_takes_back_only_that_amount(): void
    {
        $first = $this->pay(30_000, 'P1');
        $this->pay(20_000, 'P2');

        $this->as($this->w->financeA)->postJson("/api/v1/payments/{$first}/refund", ['reason' => 'Duplicate'])->assertOk();

        $invoice = $this->w->invoice->fresh();
        $this->assertSame(20_000, (int) $invoice->amountPaid);                  // the second payment still counts; the refund is stored
        $this->assertSame('partially-paid', $invoice->status);
    }
}

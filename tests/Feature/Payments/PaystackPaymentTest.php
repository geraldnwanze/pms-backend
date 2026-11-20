<?php

namespace Tests\Feature\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentService;
use Tests\PmsTestCase;
use Tests\Support\FakePaystack;

class PaystackPaymentTest extends PmsTestCase
{
    private function webhook(array $event, ?string $signature = 'valid')
    {
        $body = json_encode($event);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($signature === 'valid') {
            $headers['HTTP_X_PAYSTACK_SIGNATURE'] = hash_hmac('sha512', $body, 'sk_test_secret');
        } elseif ($signature !== null) {
            $headers['HTTP_X_PAYSTACK_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], $headers, $body);
    }

    /** Start a checkout as the tenant and return the payment row. */
    private function startCheckout(?int $amount = null, bool $saveCard = false): Payment
    {
        $id = $this->as($this->w->tenantUser)
            ->postJson('/api/v1/payments/paystack/initialize', array_filter([
                'invoiceId' => $this->w->invoice->id, 'amount' => $amount, 'saveCard' => $saveCard ?: null,
            ]))
            ->assertOk()
            ->json('data.payment.id');

        return Payment::query()->findOrFail($id);
    }

    private function paidOnPaystack(Payment $payment, int $amountNaira): void
    {
        $this->paystack->transaction($payment->gatewayReference, FakePaystack::cardTransaction($payment->gatewayReference, $amountNaira * 100));
    }

    // ---- Webhook signature --------------------------------------------------

    public function test_a_webhook_without_a_signature_is_rejected(): void
    {
        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => 'x']], null)->assertUnauthorized();
    }

    public function test_a_webhook_with_a_wrong_signature_is_rejected_and_changes_nothing(): void
    {
        $payment = $this->startCheckout();
        $this->paidOnPaystack($payment, 100_000);

        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $payment->gatewayReference]], 'deadbeef')->assertUnauthorized();

        $this->assertSame('initiated', $payment->fresh()->status);
        $this->assertSame(0, (int) $this->w->invoice->fresh()->amountPaid);
    }

    public function test_a_signature_made_with_a_different_secret_is_rejected(): void
    {
        $payment = $this->startCheckout();
        $this->paidOnPaystack($payment, 100_000);
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $payment->gatewayReference]]);

        $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_attacker'),
        ], $body)->assertUnauthorized();

        $this->assertSame('initiated', $payment->fresh()->status);
    }

    public function test_a_validly_signed_charge_success_settles_the_invoice(): void
    {
        $payment = $this->startCheckout();
        $this->paidOnPaystack($payment, 100_000);

        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $payment->gatewayReference]])->assertOk();

        $this->assertSame('approved', $payment->fresh()->status);
        $invoice = $this->w->invoice->fresh();
        $this->assertSame(100_000, (int) $invoice->amountPaid);
        $this->assertSame('fully-paid', $invoice->status);
    }

    public function test_replaying_the_same_webhook_never_double_credits(): void
    {
        $payment = $this->startCheckout();
        $this->paidOnPaystack($payment, 100_000);

        foreach (range(1, 3) as $_) {
            $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $payment->gatewayReference]])->assertOk();
        }

        $this->assertSame(100_000, (int) $this->w->invoice->fresh()->amountPaid);
        $this->assertSame(1, Payment::query()->where('invoiceId', $this->w->invoice->id)->where('status', 'approved')->count());
    }

    public function test_the_webhook_trusts_paystack_not_the_request_body(): void
    {
        // A correctly-signed event for a transaction Paystack says did NOT succeed must not settle anything.
        $payment = $this->startCheckout();
        $this->paystack->transaction($payment->gatewayReference, ['status' => 'abandoned', 'gateway_response' => 'The user did not complete the transaction']);

        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => $payment->gatewayReference, 'amount' => 10_000_000]])->assertOk();

        $this->assertNotSame('approved', $payment->fresh()->status);
        $this->assertSame(0, (int) $this->w->invoice->fresh()->amountPaid);
    }

    public function test_events_other_than_charge_success_and_unknown_references_are_ignored(): void
    {
        $this->webhook(['event' => 'transfer.success', 'data' => ['reference' => 'whatever']])->assertOk();
        $this->webhook(['event' => 'charge.success', 'data' => ['reference' => 'PSK-UNKNOWN']])->assertOk();
        $this->webhook(['event' => 'charge.success', 'data' => []])->assertOk();
        $this->assertSame(0, Payment::query()->where('status', 'approved')->count());
    }

    // ---- Amounts ------------------------------------------------------------

    public function test_a_part_payment_leaves_the_invoice_part_paid(): void
    {
        $payment = $this->startCheckout(40_000);
        $this->assertSame(40_000, (int) $payment->amount);
        $this->paidOnPaystack($payment, 40_000);

        $this->as($this->w->tenantUser)->postJson('/api/v1/payments/paystack/verify', ['reference' => $payment->gatewayReference])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $invoice = $this->w->invoice->fresh();
        $this->assertSame(40_000, (int) $invoice->amountPaid);
        $this->assertSame('partially-paid', $invoice->status);
    }

    public function test_the_amount_is_sent_to_paystack_in_kobo(): void
    {
        $this->startCheckout(40_000);

        $init = $this->paystack->to('/transaction/initialize');
        $this->assertCount(1, $init);
        $this->assertSame(4_000_000, $init[0]['data']['amount']);
        $this->assertSame('NGN', $init[0]['data']['currency']);
    }

    public function test_the_invoice_is_only_credited_with_what_paystack_actually_received(): void
    {
        $payment = $this->startCheckout(); // ₦100,000 expected
        $this->paidOnPaystack($payment, 30_000); // but only ₦30,000 arrived

        $this->as($this->w->tenantUser)->postJson('/api/v1/payments/paystack/verify', ['reference' => $payment->gatewayReference])->assertOk();

        $this->assertSame(30_000, (int) $payment->fresh()->amount);
        $this->assertSame(30_000, (int) $this->w->invoice->fresh()->amountPaid);
    }

    public function test_a_tenant_cannot_pay_more_than_is_owed_or_pay_a_settled_or_cancelled_invoice(): void
    {
        $this->as($this->w->tenantUser);

        $this->postJson('/api/v1/payments/paystack/initialize', ['invoiceId' => $this->w->invoice->id, 'amount' => 100_001])->assertStatus(422);
        $this->postJson('/api/v1/payments/paystack/initialize', ['invoiceId' => $this->w->invoice->id, 'amount' => 0])->assertStatus(422);

        $paid = $this->w->invoice(['id' => 'inv_paid', 'amountPaid' => 100_000, 'status' => 'fully-paid']);
        $this->postJson('/api/v1/payments/paystack/initialize', ['invoiceId' => $paid->id])->assertStatus(422);

        $cancelled = $this->w->invoice(['id' => 'inv_cancelled', 'status' => 'cancelled']);
        $this->postJson('/api/v1/payments/paystack/initialize', ['invoiceId' => $cancelled->id])->assertStatus(422);
    }

    public function test_a_tenant_cannot_start_checkout_for_someone_elses_invoice(): void
    {
        $other = $this->w->invoice(['id' => 'inv_other', 'tenantId' => 'ten_someone_else']);

        $this->as($this->w->tenantUser)
            ->postJson('/api/v1/payments/paystack/initialize', ['invoiceId' => $other->id])
            ->assertForbidden();
    }

    public function test_only_one_open_payment_per_invoice(): void
    {
        $this->startCheckout();

        $this->as($this->w->tenantUser)
            ->postJson('/api/v1/payments/paystack/initialize', ['invoiceId' => $this->w->invoice->id])
            ->assertStatus(409);
    }

    public function test_checkout_needs_a_configured_gateway(): void
    {
        config(['services.paystack.secret_key' => null]);

        $this->as($this->w->tenantUser)
            ->postJson('/api/v1/payments/paystack/initialize', ['invoiceId' => $this->w->invoice->id])
            ->assertStatus(503);
    }

    public function test_a_failed_checkout_is_marked_failed_and_tells_the_tenant(): void
    {
        $payment = $this->startCheckout();
        $this->paystack->transaction($payment->gatewayReference, ['status' => 'failed', 'gateway_response' => 'Declined']);

        $this->as($this->w->tenantUser)->postJson('/api/v1/payments/paystack/verify', ['reference' => $payment->gatewayReference])
            ->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertSame(0, (int) $this->w->invoice->fresh()->amountPaid);
        $this->assertDatabaseHas('notifications', ['forUserId' => 'ten_ada', 'title' => 'Online payment failed']);
    }

    public function test_settling_an_already_settled_payment_is_a_no_op(): void
    {
        $payment = $this->startCheckout(30_000);   // part-payment, so a double credit isn't hidden by the invoice total cap
        $payments = app(PaymentService::class);

        $payments->settle($payment);
        $payments->settle($payment->fresh());
        $payments->settle(Payment::query()->find($payment->id));

        $this->assertSame(30_000, (int) $this->w->invoice->fresh()->amountPaid);
    }
}

<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Paystack online payments (redirect checkout + verify + webhook).
 *
 * Flow: initialize() creates a Payment in `initiated` state and hands the SPA a
 * hosted checkout URL. When the tenant returns, or when the `charge.success`
 * webhook fires, verify() confirms the transaction with Paystack and — on
 * success — hands off to PaymentService::settle() (idempotent), which applies
 * the money to the invoice and fires the usual notifications/emails.
 */
class PaystackService
{
    public function __construct(
        private PaymentService $payments,
        private NotificationService $notifications,
    ) {}

    public function configured(): bool
    {
        return filled($this->secret());
    }

    private function secret(): ?string
    {
        return config('services.paystack.secret_key');
    }

    private function api()
    {
        return Http::baseUrl(rtrim((string) config('services.paystack.base_url'), '/'))
            ->withToken((string) $this->secret())
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * Start a checkout for one invoice. Returns the hosted-page URL + reference.
     *
     * @return array{authorizationUrl:string,reference:string,payment:Payment}
     */
    public function initialize(User $user, string $invoiceId, ?int $amount = null): array
    {
        abort_unless($this->configured(), 503, 'Online payments are not configured.');

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->findOrFail($invoiceId);

        abort_unless(
            in_array($invoice->tenantId, $user->tenantRecordIds(), true),
            403,
            'You can only pay your own invoices.',
        );
        abort_if($invoice->status === 'cancelled', 422, 'This invoice has been cancelled.');

        $outstanding = max(0, (int) $invoice->total - (int) $invoice->amountPaid);
        abort_if($outstanding <= 0, 422, 'This invoice is already settled.');

        // Block a second attempt while another payment on this invoice is open.
        $this->payments->assertNoOpenPayment($invoice->id);

        $amount = $amount ? (int) $amount : $outstanding;
        abort_if($amount <= 0 || $amount > $outstanding, 422, 'Amount must be between ₦1 and ₦'.number_format($outstanding).'.');

        $tenant = Tenant::query()->find($invoice->tenantId);
        $email = $tenant?->email ?: $user->email;
        $reference = 'PSK-'.Str::upper(Str::random(18));

        $id = 'pay_'.Str::lower(Str::random(12));
        $payment = Payment::query()->create([
            'id' => $id,
            'code' => 'PAY-'.Str::upper(substr($id, -5)),
            'invoiceId' => $invoice->id,
            'leaseId' => $invoice->leaseId,
            'unitId' => $invoice->unitId,
            'propertyId' => $invoice->propertyId,
            'tenantId' => $invoice->tenantId,
            'type' => 'rent',
            'method' => 'online',
            'amount' => $amount,
            'paymentDate' => now()->toDateString(),
            'reference' => $reference,
            'payerName' => $tenant?->name ?? $user->name,
            'description' => "Online payment for invoice {$invoice->code}",
            'status' => 'initiated',
            'submittedAt' => now()->toIso8601String(),
            'gateway' => 'paystack',
            'gatewayReference' => $reference,
        ]);

        $response = $this->api()->post('/transaction/initialize', [
            'email' => $email,
            'amount' => $amount * 100, // kobo
            'currency' => 'NGN',
            'reference' => $reference,
            'callback_url' => FrontendUrl::to((string) config('services.paystack.callback_path')),
            'metadata' => [
                'paymentId' => $payment->id,
                'invoiceId' => $invoice->id,
                'invoiceCode' => $invoice->code,
                'tenantId' => $invoice->tenantId,
            ],
        ]);

        if (! $response->ok() || ! $response->json('status')) {
            $payment->update(['status' => 'failed', 'gatewayStatus' => 'init_failed', 'rejectionReason' => $response->json('message') ?? 'Could not start checkout.']);
            Log::error('Paystack initialize failed', ['reference' => $reference, 'body' => $response->body()]);
            throw new RuntimeException($response->json('message') ?? 'Could not start the payment.');
        }

        $payment->update(['gatewayStatus' => 'pending']);

        return [
            'authorizationUrl' => (string) $response->json('data.authorization_url'),
            'reference' => $reference,
            'payment' => $payment->refresh(),
        ];
    }

    /**
     * Confirm a transaction with Paystack and settle it. Idempotent: safe to
     * call from both the browser callback and the webhook.
     */
    public function verify(string $reference, ?User $actor = null): Payment
    {
        abort_unless($this->configured(), 503, 'Online payments are not configured.');

        /** @var Payment $payment */
        $payment = Payment::query()
            ->where('gateway', 'paystack')
            ->where('gatewayReference', $reference)
            ->firstOrFail();

        if ($payment->status === 'approved') {
            return $payment;
        }

        $response = $this->api()->get("/transaction/verify/{$reference}");
        $data = $response->json('data') ?? [];
        $gwStatus = $data['status'] ?? 'unknown';

        $payment->fill([
            'gatewayStatus' => $gwStatus,
            'channel' => $data['channel'] ?? null,
            'feesAmount' => isset($data['fees']) ? (int) round($data['fees'] / 100) : null,
            'paidAt' => $data['paid_at'] ?? null,
        ]);

        if (! $response->ok() || ! $response->json('status') || $gwStatus !== 'success') {
            $payment->status = in_array($gwStatus, ['failed', 'abandoned', 'reversed'], true) ? 'failed' : $payment->status;
            $payment->rejectionReason = $data['gateway_response'] ?? 'Payment was not completed.';
            $payment->save();

            if ($payment->status === 'failed') {
                $this->notifications->push([
                    'kind' => 'payment', 'title' => 'Online payment failed',
                    'body' => "Payment {$payment->code} did not go through: {$payment->rejectionReason}",
                    'forUserId' => $payment->tenantId, 'link' => '/portal/pay',
                ]);
            }

            return $payment->refresh();
        }

        // Amount guard — never credit more than was actually paid.
        $paidNaira = (int) round(((int) ($data['amount'] ?? 0)) / 100);
        if ($paidNaira > 0 && $paidNaira < $payment->amount) {
            $payment->amount = $paidNaira;
        }
        $payment->paymentDate = isset($data['paid_at']) ? substr((string) $data['paid_at'], 0, 10) : now()->toDateString();
        $payment->save();

        return $this->payments->settle($payment, $actor, 'Verified via Paystack ('.$reference.').');
    }

    /**
     * Process a Paystack webhook. Throws on a bad signature so the caller can
     * return 401; otherwise handles `charge.success` and returns quietly.
     */
    public function handleWebhook(string $rawBody, ?string $signature): void
    {
        $expected = hash_hmac('sha512', $rawBody, (string) $this->secret());
        abort_unless($signature && hash_equals($expected, $signature), 401, 'Invalid signature.');

        $event = json_decode($rawBody, true) ?: [];
        if (($event['event'] ?? null) !== 'charge.success') {
            return;
        }

        $reference = $event['data']['reference'] ?? null;
        if (! $reference) {
            return;
        }

        try {
            $this->verify($reference);
        } catch (\Throwable $e) {
            // Unknown reference (e.g. a transaction started elsewhere) — log, don't fail the webhook.
            Log::info('Paystack webhook: could not settle', ['reference' => $reference, 'error' => $e->getMessage()]);
        }
    }
}

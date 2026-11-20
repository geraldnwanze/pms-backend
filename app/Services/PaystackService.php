<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Http\Client\ConnectionException;
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
    public function initialize(User $user, string $invoiceId, ?int $amount = null, bool $saveCard = false): array
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
            'saveCard' => $saveCard,
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
                'saveCard' => $saveCard,
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

        // The tenant ticked "save this card for auto-debit": keep Paystack's reusable token (never the card).
        if ($payment->saveCard) {
            try {
                app(AutoDebitService::class)->captureFromTransaction((string) $payment->tenantId, $data);
            } catch (\Throwable $e) {
                Log::warning('Auto-debit: could not save card', ['reference' => $reference, 'error' => $e->getMessage()]);
            }
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
            if (AutoDebitService::isSetupReference($reference)) {
                app(AutoDebitService::class)->completeSetup($reference);

                return;
            }

            $this->verify($reference);
        } catch (\Throwable $e) {
            // Unknown reference (e.g. a transaction started elsewhere) — log, don't fail the webhook.
            Log::info('Paystack webhook: could not settle', ['reference' => $reference, 'error' => $e->getMessage()]);
        }
    }

    // ---- Primitives used by auto-debit ------------------------------------------

    /**
     * Start a hosted checkout that isn't tied to an invoice (used for the card-setup charge).
     *
     * @param  array<string,mixed>  $metadata
     * @param  array<int,string>|null  $channels
     * @return array{ok:bool,authorizationUrl:?string,message:?string}
     */
    public function startTransaction(string $email, int $amountKobo, string $reference, array $metadata = [], ?array $channels = null): array
    {
        abort_unless($this->configured(), 503, 'Online payments are not configured.');

        $response = $this->api()->post('/transaction/initialize', array_filter([
            'email' => $email,
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'reference' => $reference,
            'callback_url' => FrontendUrl::to((string) config('services.paystack.callback_path')),
            'channels' => $channels,
            'metadata' => $metadata,
        ], fn ($v) => $v !== null));

        $ok = $response->ok() && $response->json('status');

        return [
            'ok' => (bool) $ok,
            'authorizationUrl' => $ok ? (string) $response->json('data.authorization_url') : null,
            'message' => $response->json('message'),
        ];
    }

    /** The raw Paystack transaction (verify endpoint), or null when Paystack can't be reached / doesn't know it. */
    public function fetchTransaction(string $reference): ?array
    {
        $response = $this->api()->get("/transaction/verify/{$reference}");

        return $response->ok() && $response->json('status') ? ($response->json('data') ?? []) : null;
    }

    /**
     * Charge a saved card (authorization) without the customer present.
     *
     * Returns the gateway status ('success' | 'failed' | 'pending' | …). A transport failure
     * (timeout, DNS) is reported as 'unknown' — NOT 'failed' — because the charge may have
     * gone through; the caller must reconcile by verifying the reference later.
     *
     * @param  array<string,mixed>  $metadata
     * @return array{status:string,message:?string,httpOk:bool,data:array<string,mixed>}
     */
    public function chargeAuthorization(string $email, int $amountKobo, string $authorizationCode, string $reference, array $metadata = []): array
    {
        abort_unless($this->configured(), 503, 'Online payments are not configured.');

        try {
            $response = $this->api()->post('/transaction/charge_authorization', [
                'email' => $email,
                'amount' => $amountKobo,
                'currency' => 'NGN',
                'authorization_code' => $authorizationCode,
                'reference' => $reference,
                'metadata' => $metadata,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Paystack charge_authorization: no response', ['reference' => $reference, 'error' => $e->getMessage()]);

            return ['status' => 'unknown', 'message' => 'No response from Paystack.', 'httpOk' => false, 'data' => []];
        }

        $data = $response->json('data') ?? [];

        if (! $response->ok() || ! $response->json('status')) {
            return ['status' => 'failed', 'message' => $response->json('message') ?? 'The charge was declined.', 'httpOk' => false, 'data' => $data];
        }

        return [
            'status' => (string) ($data['status'] ?? 'unknown'),
            'message' => $data['gateway_response'] ?? $response->json('message'),
            'httpOk' => true,
            'data' => $data,
        ];
    }

    /** Best-effort full refund of a transaction. Returns whether Paystack accepted the request. */
    public function refund(string $reference): bool
    {
        try {
            $response = $this->api()->post('/refund', ['transaction' => $reference]);

            return $response->ok() && (bool) $response->json('status');
        } catch (\Throwable $e) {
            Log::warning('Paystack refund failed', ['reference' => $reference, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Best-effort: tell Paystack a saved card may no longer be charged. */
    public function deactivateAuthorization(string $authorizationCode): void
    {
        try {
            $this->api()->post('/customer/deactivate_authorization', ['authorization_code' => $authorizationCode]);
        } catch (\Throwable $e) {
            Log::warning('Paystack deactivate_authorization failed', ['error' => $e->getMessage()]);
        }
    }
}

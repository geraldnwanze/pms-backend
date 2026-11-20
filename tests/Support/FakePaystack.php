<?php

namespace Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An in-memory stand-in for the Paystack API. Nothing here touches the network.
 *
 * Queue charge outcomes with `->charges('success', 'declined', ...)`; once the queue is empty
 * `$defaultCharge` applies. Outcomes: success | declined | revoked | timeout | pending.
 * Register hosted-checkout transactions (card setup) with `->transaction($reference, [...])`.
 */
final class FakePaystack
{
    /** @var array<int,string> */
    public array $queue = [];

    public string $defaultCharge = 'success';

    /** @var array<string,array<string,mixed>> reference => Paystack transaction data */
    public array $transactions = [];

    /** @var array<int,array{url:string,data:array<string,mixed>}> */
    public array $calls = [];

    public bool $refundAccepted = true;

    public static function install(): self
    {
        $fake = new self;

        Http::fake(fn (Request $request) => $fake->handle($request));

        return $fake;
    }

    public function charges(string ...$outcomes): self
    {
        $this->queue = array_values($outcomes);

        return $this;
    }

    /** @param array<string,mixed> $data */
    public function transaction(string $reference, array $data): self
    {
        $this->transactions[$reference] = $data + ['reference' => $reference];

        return $this;
    }

    /** A successful, reusable card transaction as Paystack returns it from /transaction/verify. */
    public static function cardTransaction(string $reference, int $amountKobo = 5_000, array $auth = []): array
    {
        return [
            'status' => 'success',
            'reference' => $reference,
            'amount' => $amountKobo,
            'channel' => 'card',
            'paid_at' => now()->toIso8601String(),
            'fees' => 0,
            'gateway_response' => 'Successful',
            'customer' => ['email' => 'ada@tenant.test'],
            'authorization' => $auth + [
                'authorization_code' => 'AUTH_secret123',
                'reusable' => true,
                'channel' => 'card',
                'brand' => 'visa',
                'last4' => '4081',
                'exp_month' => '12',
                'exp_year' => (string) (now()->year + 3),
                'bank' => 'TEST BANK',
                'signature' => 'SIG_abc',
            ],
        ];
    }

    /** Requests made to endpoints whose URL contains $needle. */
    public function to(string $needle): array
    {
        return array_values(array_filter($this->calls, fn ($c) => str_contains($c['url'], $needle)));
    }

    public function handle(Request $request)
    {
        $url = $request->url();
        $data = $request->data();
        $this->calls[] = ['url' => $url, 'data' => $data];

        if (str_contains($url, '/transaction/initialize')) {
            return Http::response(['status' => true, 'message' => 'Authorization URL created', 'data' => [
                'authorization_url' => 'https://checkout.paystack.test/'.$data['reference'],
                'access_code' => 'ac_test', 'reference' => $data['reference'],
            ]]);
        }

        if (str_contains($url, '/transaction/charge_authorization')) {
            return $this->charge($data);
        }

        if (str_contains($url, '/transaction/verify/')) {
            $reference = urldecode(substr($url, strrpos($url, '/') + 1));
            $tx = $this->transactions[$reference] ?? null;

            return $tx
                ? Http::response(['status' => true, 'message' => 'Verification successful', 'data' => $tx])
                : Http::response(['status' => false, 'message' => 'Transaction reference not found.'], 404);
        }

        if (str_contains($url, '/refund')) {
            return Http::response(['status' => $this->refundAccepted, 'message' => 'Refund has been queued for processing'], $this->refundAccepted ? 200 : 400);
        }

        if (str_contains($url, '/customer/deactivate_authorization')) {
            return Http::response(['status' => true, 'message' => 'Authorization has been deactivated']);
        }

        return Http::response(['status' => false, 'message' => 'Unhandled in FakePaystack: '.$url], 500);
    }

    /** @param array<string,mixed> $data */
    private function charge(array $data)
    {
        $outcome = array_shift($this->queue) ?? $this->defaultCharge;
        $ref = $data['reference'];

        switch ($outcome) {
            case 'timeout':
                throw new ConnectionException('cURL error 28: Operation timed out');
            case 'revoked':
                return Http::response(['status' => false, 'message' => 'Authorization has been deactivated'], 400);

            case 'declined':
                return Http::response(['status' => true, 'message' => 'Charge attempted', 'data' => [
                    'status' => 'failed', 'reference' => $ref, 'gateway_response' => 'Insufficient Funds', 'amount' => $data['amount'],
                ]]);

            case 'pending':
                return Http::response(['status' => true, 'message' => 'Charge attempted', 'data' => [
                    'status' => 'pending', 'reference' => $ref, 'gateway_response' => 'Pending', 'amount' => $data['amount'],
                ]]);

            default: // success
                $this->transactions[$ref] = self::cardTransaction($ref, (int) $data['amount']);

                return Http::response(['status' => true, 'message' => 'Charge attempted', 'data' => $this->transactions[$ref]]);
        }
    }
}

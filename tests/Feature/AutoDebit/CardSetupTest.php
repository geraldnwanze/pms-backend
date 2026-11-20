<?php

namespace Tests\Feature\AutoDebit;

use App\Models\AutoDebitMandate;
use App\Models\AutoDebitSetup;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakePaystack;
use Tests\Support\World;

class CardSetupTest extends AutoDebitTestCase
{
    private function startSetup(): string
    {
        return $this->as($this->w->tenantUser)->postJson('/api/v1/auto-debit/setup')->assertOk()->json('data.reference');
    }

    private function cardPaid(string $reference, array $auth = []): void
    {
        $this->paystack->transaction($reference, FakePaystack::cardTransaction($reference, 5_000, $auth));
    }

    private function complete(string $reference, $as = null)
    {
        return $this->as($as ?? $this->w->tenantUser)->postJson('/api/v1/payments/paystack/verify', ['reference' => $reference]);
    }

    // ---- Starting -----------------------------------------------------------

    public function test_setup_opens_a_card_only_checkout_for_fifty_naira(): void
    {
        $res = $this->as($this->w->tenantUser)->postJson('/api/v1/auto-debit/setup')->assertOk();

        $this->assertStringStartsWith('ADS-', $res->json('data.reference'));
        $this->assertStringStartsWith('https://checkout.paystack.test/', $res->json('data.authorizationUrl'));

        $init = $this->paystack->to('/transaction/initialize')[0]['data'];
        $this->assertSame(5_000, $init['amount']);          // ₦50 in kobo
        $this->assertSame(['card'], $init['channels']);      // transfers can't be charged again later
        $this->assertSame('ada@tenant.test', $init['email']);
        $this->assertDatabaseHas('auto_debit_setups', ['reference' => $res->json('data.reference'), 'tenantId' => 'ten_ada', 'status' => 'initiated']);
    }

    public function test_only_tenants_can_start_a_setup(): void
    {
        foreach (['managerA', 'ownerA', 'financeA', 'stranger'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/auto-debit/setup')->assertForbidden();
        }
        $this->assertSame(0, AutoDebitSetup::query()->count());
    }

    public function test_setup_needs_a_configured_gateway(): void
    {
        config(['services.paystack.secret_key' => null]);

        $this->as($this->w->tenantUser)->postJson('/api/v1/auto-debit/setup')->assertStatus(503);
        $this->as($this->w->tenantUser)->getJson('/api/v1/auto-debit')->assertOk()->assertJsonPath('data.available', false);
    }

    // ---- Completing ---------------------------------------------------------

    public function test_a_paid_reusable_card_turns_auto_debit_on_and_the_fifty_naira_is_refunded(): void
    {
        $ref = $this->startSetup();
        $this->cardPaid($ref);

        $this->complete($ref)->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.autoDebit.card.label', 'Visa •••• 4081');

        $this->assertDatabaseHas('auto_debit_mandates', ['tenantId' => 'ten_ada', 'status' => 'active', 'last4' => '4081']);
        $this->assertSame('verified', AutoDebitSetup::query()->where('reference', $ref)->value('status'));
        $this->assertSame($ref, $this->paystack->to('/refund')[0]['data']['transaction']);
        $this->assertContains('Auto-debit is on', $this->tenantNotifications());
    }

    public function test_the_card_token_is_encrypted_at_rest_and_never_serialised(): void
    {
        $ref = $this->startSetup();
        $this->cardPaid($ref);
        $this->complete($ref)->assertOk();

        $raw = DB::table('auto_debit_mandates')->value('authorizationCode');
        $this->assertStringNotContainsString(self::CARD_CODE, $raw);
        $this->assertSame(self::CARD_CODE, AutoDebitMandate::query()->first()->authorizationCode);
        $this->assertArrayNotHasKey('authorizationCode', AutoDebitMandate::query()->first()->toArray());

        $status = $this->as($this->w->tenantUser)->getJson('/api/v1/auto-debit')->assertOk();
        $this->assertStringNotContainsString(self::CARD_CODE, $status->getContent());
        $this->assertStringNotContainsString('authorizationCode', $status->getContent());
        $this->assertStringNotContainsString('authorization_code', $status->getContent());
    }

    public function test_completing_twice_is_idempotent(): void
    {
        $ref = $this->startSetup();
        $this->cardPaid($ref);

        $this->complete($ref)->assertOk();
        $this->complete($ref)->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame(1, AutoDebitMandate::query()->count());
        $this->assertCount(1, $this->paystack->to('/refund'));
    }

    public function test_the_webhook_can_complete_a_setup_when_the_tenant_never_returns(): void
    {
        $ref = $this->startSetup();
        $this->cardPaid($ref);

        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $ref]]);
        $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_secret'),
        ], $body)->assertOk();

        $this->assertSame(1, AutoDebitMandate::query()->where('status', 'active')->count());
    }

    public function test_cards_that_cannot_be_charged_again_are_refused(): void
    {
        foreach ([
            ['reusable' => false],                         // one-off card
            ['channel' => 'bank_transfer'],                // not a card
            ['authorization_code' => null],                // no token
        ] as $i => $auth) {
            $ref = $this->startSetup();
            $this->cardPaid($ref, $auth);

            $this->complete($ref)->assertOk()->assertJsonPath('data.status', 'failed');
        }

        $this->assertSame(0, AutoDebitMandate::query()->count());
    }

    public function test_an_unpaid_or_abandoned_checkout_does_not_enrol_anyone(): void
    {
        $ref = $this->startSetup();
        $this->paystack->transaction($ref, ['status' => 'abandoned', 'gateway_response' => 'The user did not complete the transaction']);

        $this->complete($ref)->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertSame(0, AutoDebitMandate::query()->count());
        $this->assertSame('failed', AutoDebitSetup::query()->where('reference', $ref)->value('status'));
        $this->assertCount(0, $this->paystack->to('/refund'));
    }

    public function test_another_tenant_cannot_complete_my_setup(): void
    {
        $ref = $this->startSetup();
        $this->cardPaid($ref);

        $mallory = World::user('tenant', 'mallory@tenant.test');
        World::member($this->w->pA, $mallory, 'tenant', 'ten_mallory');

        $this->complete($ref, $mallory)->assertForbidden();
        $this->assertSame(0, AutoDebitMandate::query()->count());
    }

    // ---- One card per tenant ------------------------------------------------

    public function test_a_new_card_replaces_the_old_one_and_revokes_its_token(): void
    {
        $old = $this->mandate();

        $ref = $this->startSetup();
        $this->cardPaid($ref, ['authorization_code' => 'AUTH_new', 'last4' => '1111', 'signature' => 'SIG_other']);
        $this->complete($ref)->assertOk();

        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame(1, AutoDebitMandate::query()->where('status', 'active')->count());
        $this->assertSame('1111', AutoDebitMandate::query()->where('status', 'active')->value('last4'));
        $this->assertSame(self::CARD_CODE, $this->paystack->to('/customer/deactivate_authorization')[0]['data']['authorization_code']);
    }

    public function test_saving_the_same_card_again_refreshes_it_instead_of_duplicating(): void
    {
        $this->mandate();

        $ref = $this->startSetup();
        $this->cardPaid($ref, ['authorization_code' => 'AUTH_refreshed']);   // same signature SIG_abc
        $this->complete($ref)->assertOk();

        $this->assertSame(1, AutoDebitMandate::query()->count());
        $this->assertSame('AUTH_refreshed', AutoDebitMandate::query()->first()->authorizationCode);
        $this->assertCount(0, $this->paystack->to('/customer/deactivate_authorization'));
    }

    // ---- Turning off --------------------------------------------------------

    public function test_turning_off_forgets_the_card_revokes_the_token_and_tells_the_tenant(): void
    {
        $this->mandate();

        $this->as($this->w->tenantUser)->deleteJson('/api/v1/auto-debit')->assertOk()->assertJsonPath('message', 'Auto-debit turned off.');

        $this->assertSame('cancelled', AutoDebitMandate::query()->first()->status);
        $this->assertCount(1, $this->paystack->to('/customer/deactivate_authorization'));
        $this->assertContains('Auto-debit turned off', $this->tenantNotifications());
        $this->as($this->w->tenantUser)->getJson('/api/v1/auto-debit')->assertJsonPath('data.enabled', false);
    }

    public function test_turning_off_when_it_was_never_on_is_harmless(): void
    {
        $this->as($this->w->tenantUser)->deleteJson('/api/v1/auto-debit')->assertOk()->assertJsonPath('message', 'Auto-debit was not on.');
        $this->assertCount(0, $this->paystack->to('/customer/deactivate_authorization'));
    }

    public function test_a_tenant_can_only_turn_off_their_own_card(): void
    {
        $this->mandate();
        $bob = World::user('tenant', 'bob@tenant.test');
        World::member($this->w->pA, $bob, 'tenant', 'ten_bob');

        $this->as($bob)->deleteJson('/api/v1/auto-debit')->assertOk()->assertJsonPath('message', 'Auto-debit was not on.');
        $this->assertSame('active', AutoDebitMandate::query()->first()->status);

        // staff have no way to enrol or alter a tenant's card
        $this->as($this->w->managerA)->deleteJson('/api/v1/auto-debit')->assertForbidden();
        $this->assertSame('active', AutoDebitMandate::query()->first()->status);
    }
}

<?php

namespace Tests\Feature\AutoDebit;

use App\Models\AutoDebitMandate;
use Tests\Support\World;

class AutoDebitApiTest extends AutoDebitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mandate();
        $this->dueOn('2026-11-11');
    }

    // ---- Tenant status ------------------------------------------------------

    public function test_the_tenant_sees_their_card_and_the_queued_invoices(): void
    {
        $res = $this->as($this->w->tenantUser)->getJson('/api/v1/auto-debit')->assertOk();

        $res->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.card.label', 'Visa •••• 4081')
            ->assertJsonPath('data.maxAttempts', 3)
            ->assertJsonPath('data.setupAmount', 50)
            ->assertJsonPath('data.upcoming.0.invoiceId', $this->w->invoice->id)
            ->assertJsonPath('data.upcoming.0.state', 'scheduled')
            ->assertJsonPath('data.upcoming.0.amount', 100_000);
        $this->assertStringNotContainsString(self::CARD_CODE, $res->getContent());
    }

    public function test_upcoming_shows_retrying_and_manual_states_after_failures(): void
    {
        $this->paystack->defaultCharge = 'declined';

        $this->runOn('2026-11-11');
        $this->as($this->w->tenantUser)->getJson('/api/v1/auto-debit')
            ->assertJsonPath('data.upcoming.0.state', 'retrying')
            ->assertJsonPath('data.upcoming.0.nextAttemptOn', '2026-11-12')
            ->assertJsonPath('data.upcoming.0.lastReason', 'Insufficient Funds');

        $this->runOn('2026-11-12');
        $this->runOn('2026-11-14');
        $this->as($this->w->tenantUser)->getJson('/api/v1/auto-debit')->assertJsonPath('data.upcoming.0.state', 'manual');
    }

    public function test_invoices_that_fell_due_before_enrolment_are_not_listed_as_upcoming(): void
    {
        $this->w->invoice(['id' => 'inv_old', 'dueDate' => '2026-09-01']);

        $ids = collect($this->as($this->w->tenantUser)->getJson('/api/v1/auto-debit')->json('data.upcoming'))->pluck('invoiceId');

        $this->assertNotContains('inv_old', $ids);
    }

    public function test_status_is_for_tenants_only(): void
    {
        foreach (['managerA', 'ownerA', 'stranger'] as $who) {
            $this->as($this->w->{$who})->getJson('/api/v1/auto-debit')->assertForbidden();
        }
    }

    public function test_it_needs_a_login(): void
    {
        foreach (['/api/v1/auto-debit', '/api/v1/auto-debit/overview', '/api/v1/auto-debit/invoices/x'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson('/api/v1/auto-debit/setup')->assertUnauthorized();
        $this->deleteJson('/api/v1/auto-debit')->assertUnauthorized();
    }

    // ---- Staff overview -----------------------------------------------------

    public function test_staff_see_the_state_of_invoices_on_their_own_properties(): void
    {
        foreach (['ownerA', 'managerA', 'financeA', 'admin'] as $who) {
            $res = $this->as($this->w->{$who})->getJson('/api/v1/auto-debit/overview')->assertOk();

            $res->assertJsonPath("data.invoices.{$this->w->invoice->id}.state", 'scheduled')
                ->assertJsonPath('data.counts.scheduled', 1)
                ->assertJsonPath('data.enrolledTenants.0', 'ten_ada');
        }
    }

    public function test_staff_never_see_another_portfolios_invoices(): void
    {
        $res = $this->as($this->w->ownerB)->getJson('/api/v1/auto-debit/overview')->assertOk();

        $this->assertSame([], $res->json('data.invoices'));
        $this->assertSame([], $res->json('data.enrolledTenants'));
    }

    public function test_the_overview_never_exposes_the_card_or_its_token(): void
    {
        $this->runOn('2026-11-11');

        $body = $this->as($this->w->ownerA)->getJson('/api/v1/auto-debit/overview')->getContent();

        foreach ([self::CARD_CODE, 'authorizationCode', '4081', 'SIG_abc', 'ada@tenant.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_tenants_strangers_and_agents_cannot_use_the_staff_endpoints(): void
    {
        $agent = World::user('agent', 'agent@pms.test');
        World::member($this->w->pA, $agent, 'agent');

        foreach (['tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who});
            $this->getJson('/api/v1/auto-debit/overview')->assertForbidden();
            $this->getJson("/api/v1/auto-debit/invoices/{$this->w->invoice->id}")->assertForbidden();
        }
        $this->as($agent)->getJson('/api/v1/auto-debit/overview')->assertForbidden();
    }

    // ---- One invoice --------------------------------------------------------

    public function test_invoice_detail_lists_the_attempts_in_order(): void
    {
        $this->paystack->defaultCharge = 'declined';
        $this->runOn('2026-11-11');
        $this->runOn('2026-11-12');

        $res = $this->as($this->w->managerA)->getJson("/api/v1/auto-debit/invoices/{$this->w->invoice->id}")->assertOk();

        $res->assertJsonPath('data.enrolled', true)
            ->assertJsonPath('data.state', 'retrying')
            ->assertJsonPath('data.maxAttempts', 3)
            ->assertJsonPath('data.attempts.0.attemptNo', 1)
            ->assertJsonPath('data.attempts.1.attemptNo', 2)
            ->assertJsonPath('data.attempts.1.reason', 'Insufficient Funds');
    }

    public function test_invoice_detail_is_forbidden_outside_the_callers_properties(): void
    {
        $this->as($this->w->ownerB)->getJson("/api/v1/auto-debit/invoices/{$this->w->invoice->id}")->assertForbidden();
    }

    public function test_invoice_detail_of_a_tenant_not_on_auto_debit_has_no_state(): void
    {
        AutoDebitMandate::query()->delete();

        $this->as($this->w->managerA)->getJson("/api/v1/auto-debit/invoices/{$this->w->invoice->id}")
            ->assertOk()->assertJsonPath('data.enrolled', false)->assertJsonPath('data.state', null);
    }

    public function test_history_survives_the_tenant_turning_auto_debit_off(): void
    {
        $this->runOn('2026-11-11');
        $this->as($this->w->tenantUser)->deleteJson('/api/v1/auto-debit')->assertOk();

        $this->as($this->w->managerA)->getJson("/api/v1/auto-debit/invoices/{$this->w->invoice->id}")
            ->assertOk()->assertJsonPath('data.enrolled', false)->assertJsonPath('data.state', 'paid')->assertJsonCount(1, 'data.attempts');
    }

    public function test_an_unknown_invoice_is_a_404(): void
    {
        $this->as($this->w->admin)->getJson('/api/v1/auto-debit/invoices/nope')->assertNotFound();
    }
}

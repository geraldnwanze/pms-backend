<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Tests\PmsTestCase;

class AdminAndSettingsTest extends PmsTestCase
{
    // ---- Demo reset: wipes every table ---------------------------------------

    public function test_only_an_admin_can_reset(): void
    {
        foreach (['ownerA', 'managerA', 'financeA', 'tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/admin/reset')->assertForbidden();
        }
        $this->assertNotNull(Property::query()->find('prop_a'), 'a refused reset must not touch the data');
    }

    public function test_the_reset_endpoint_requires_a_login(): void
    {
        $this->postJson('/api/v1/admin/reset')->assertUnauthorized();
    }

    public function test_reset_is_unavailable_in_production_even_to_an_admin(): void
    {
        $this->app['env'] = 'production';

        $status = $this->as($this->w->admin)->postJson('/api/v1/admin/reset')->status();

        $this->assertContains($status, [403, 404]);
        $this->assertNotNull(Property::query()->find('prop_a'), 'the live data must survive');
        $this->assertNotNull(Invoice::query()->find($this->w->invoice->id));
        $this->assertNotNull(User::query()->find($this->w->tenantUser->id));
    }

    public function test_an_admin_can_reset_in_a_local_environment(): void
    {
        $this->as($this->w->admin)->postJson('/api/v1/admin/reset')->assertOk();

        $this->assertNull(Property::query()->find('prop_a'), 'the fixture data is replaced by the demo dataset');
        $this->assertNotNull(User::query()->find('admin_1'));
    }

    // ---- Settings ---------------------------------------------------------------

    public function test_anyone_signed_in_can_read_the_organisation_profile_but_only_an_admin_can_change_it(): void
    {
        $this->as($this->w->tenantUser)->getJson('/api/v1/settings/organization')->assertOk();

        foreach (['ownerA', 'managerA', 'financeA', 'tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who})->putJson('/api/v1/settings/organization', ['name' => 'Hacked Ltd'])->assertForbidden();
            $this->putJson('/api/v1/integrations', ['paystack' => ['enabled' => false]])->assertForbidden();
        }
        $this->assertStringNotContainsString('Hacked', $this->as($this->w->admin)->getJson('/api/v1/settings/organization')->getContent());
    }

    public function test_settings_need_a_login(): void
    {
        $this->getJson('/api/v1/settings/organization')->assertUnauthorized();
        $this->putJson('/api/v1/settings/organization', ['name' => 'x'])->assertUnauthorized();
        $this->getJson('/api/v1/integrations')->assertUnauthorized();
    }

    public function test_an_admin_can_save_valid_settings_and_it_is_audited(): void
    {
        $this->as($this->w->admin)->putJson('/api/v1/settings/organization', ['name' => 'Acme Estates', 'lateFeePercent' => 2.5, 'gracePeriodDays' => 5])
            ->assertOk()->assertJsonPath('data.name', 'Acme Estates');

        $this->getJson('/api/v1/settings/organization')->assertJsonPath('data.name', 'Acme Estates')->assertJsonPath('data.gracePeriodDays', 5);
        $this->assertDatabaseHas('audit', ['entity' => 'Settings', 'userId' => $this->w->admin->id]);
    }

    public function test_settings_are_validated(): void
    {
        $this->as($this->w->admin);

        foreach ([['lateFeePercent' => 101], ['lateFeePercent' => -1], ['gracePeriodDays' => 91], ['supportEmail' => 'nope'], ['fiscalYearStart' => 'March'], ['invoicePrefix' => 'WAYTOOLONGPREFIX'], ['name' => str_repeat('a', 121)]] as $bad) {
            $this->putJson('/api/v1/settings/organization', $bad)->assertStatus(422);
        }
    }

    public function test_integrations_only_accept_the_safe_fields_so_no_secrets_can_be_stored_or_returned(): void
    {
        $res = $this->as($this->w->admin)->putJson('/api/v1/integrations', [
            'paystack' => ['enabled' => true, 'label' => 'Paystack', 'secretKey' => 'sk_live_LEAK', 'apiKey' => 'LEAK2', 'webhook' => 'https://evil.test'],
            'notAnObject' => 'ignored',
        ])->assertOk();

        $body = $res->getContent().$this->getJson('/api/v1/integrations')->getContent();
        foreach (['sk_live_LEAK', 'LEAK2', 'evil.test', 'secretKey', 'apiKey', 'ignored'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
        $this->assertStringContainsString('Paystack', $body);
    }

    public function test_the_rbac_matrix_and_bootstrap_do_not_leak_server_secrets(): void
    {
        foreach (['/api/v1/rbac', '/api/v1/bootstrap', '/api/v1/integrations', '/api/v1/settings/organization'] as $url) {
            $body = $this->as($this->w->admin)->getJson($url)->getContent();
            foreach (['sk_test_secret', 'APP_KEY', 'base64:', 'password', 'remember_token'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "{$url} leaked {$secret}");
            }
        }
    }

    // ---- Broadcast channels ------------------------------------------------------

    private function channel(string $pattern): callable
    {
        $channels = Broadcast::driver()->getChannels();
        $this->assertTrue($channels->has($pattern), "channel {$pattern} is not registered");

        return $channels->get($pattern);
    }

    public function test_a_user_can_only_listen_to_their_own_notification_channel(): void
    {
        $callback = $this->channel('notifications.user.{id}');

        $this->assertTrue($callback($this->w->ownerA, $this->w->ownerA->id));
        $this->assertFalse($callback($this->w->ownerA, $this->w->ownerB->id));
        $this->assertFalse($callback($this->w->ownerA, $this->w->admin->id));
        $this->assertFalse($callback($this->w->stranger, $this->w->tenant->id));
    }

    public function test_a_tenant_can_listen_to_their_own_tenant_record_channel_only(): void
    {
        $callback = $this->channel('notifications.user.{id}');

        $this->assertTrue($callback($this->w->tenantUser, 'ten_ada'));
        $this->assertFalse($callback($this->w->tenantUser, 'ten_bob'));
    }

    public function test_role_channels_are_only_open_to_that_role(): void
    {
        $callback = $this->channel('notifications.role.{role}');

        $this->assertTrue($callback($this->w->financeA, 'finance'));
        $this->assertFalse($callback($this->w->ownerA, 'finance'));
        $this->assertFalse($callback($this->w->tenantUser, 'admin'));
        $this->assertFalse($callback($this->w->stranger, 'admin'));
    }

    public function test_the_broadcast_auth_endpoint_requires_a_login(): void
    {
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-notifications.user.usr_admin'])->assertUnauthorized();
    }
}

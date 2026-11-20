<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\PmsTestCase;

/** An administrator deactivating an account locks it out everywhere, at once. */
class DeactivatedAccountTest extends PmsTestCase
{
    private const EMAIL = 'manager.a@pms.test';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    private function signIn(string $password = 'password')
    {
        return $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => $password]);
    }

    private function deactivate(): void
    {
        $this->as($this->w->admin)->patchJson("/api/v1/users/{$this->w->managerA->id}", ['status' => 'inactive'])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    public function test_a_deactivated_account_cannot_sign_in_even_with_the_right_password(): void
    {
        $this->deactivate();

        $this->signIn()->assertStatus(422)->assertJsonPath('errors.email.0', 'This account has been deactivated. Contact your administrator.');
        $this->assertSame(0, $this->w->managerA->fresh()->tokens()->count());
    }

    public function test_a_wrong_password_is_still_just_a_wrong_password(): void
    {
        $this->deactivate();

        $this->signIn('wrong')->assertStatus(422)->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    public function test_deactivating_signs_the_person_out_immediately(): void
    {
        $token = $this->signIn()->assertOk()->json('data.token');
        $this->assertSame(200, $this->withToken($token)->getJson('/api/v1/properties')->status());
        $this->app['auth']->forgetGuards();

        $this->deactivate();

        $this->assertSame(401, $this->withToken($token)->getJson('/api/v1/properties')->status());
    }

    public function test_a_token_that_survives_somehow_is_refused_and_removed(): void
    {
        $token = $this->signIn()->assertOk()->json('data.token');
        $this->w->managerA->forceFill(['status' => 'inactive'])->save();       // flipped without going through the API
        $this->app['auth']->forgetGuards();

        $this->assertSame(401, $this->withToken($token)->getJson('/api/v1/properties')->status());
        $this->assertSame(0, $this->w->managerA->tokens()->count());
    }

    public function test_reactivating_restores_access(): void
    {
        $this->deactivate();
        $this->as($this->w->admin)->patchJson("/api/v1/users/{$this->w->managerA->id}", ['status' => 'active'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->signIn()->assertOk();
    }

    public function test_a_deactivated_account_cannot_get_back_in_through_a_password_reset(): void
    {
        $this->deactivate();

        $res = $this->postJson('/api/v1/auth/forgot-password', ['email' => self::EMAIL])->assertOk();
        $this->assertNull($res->json('data'));
        Mail::assertNothingQueued();

        // and a token minted before deactivation no longer works
        User::query()->whereKey($this->w->managerA->id)->update(['status' => 'active']);
        $token = $this->postJson('/api/v1/auth/forgot-password', ['email' => self::EMAIL])->json('data.reset_token');
        User::query()->whereKey($this->w->managerA->id)->update(['status' => 'inactive']);

        $this->postJson('/api/v1/auth/reset-password', ['email' => self::EMAIL, 'token' => $token, 'password' => 'a-brand-new-pass', 'password_confirmation' => 'a-brand-new-pass'])
            ->assertStatus(422);
        $this->signIn('a-brand-new-pass')->assertStatus(422);
    }

    public function test_an_administrator_changing_a_password_signs_that_person_out_too(): void
    {
        $token = $this->signIn()->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->as($this->w->admin)->patchJson("/api/v1/users/{$this->w->managerA->id}", ['password' => 'set-by-an-admin'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertSame(401, $this->withToken($token)->getJson('/api/v1/properties')->status());
        $this->signIn('set-by-an-admin')->assertOk();
    }

    public function test_other_accounts_are_unaffected(): void
    {
        $this->deactivate();

        $this->postJson('/api/v1/auth/login', ['email' => 'owner.a@pms.test', 'password' => 'password'])->assertOk();
    }
}

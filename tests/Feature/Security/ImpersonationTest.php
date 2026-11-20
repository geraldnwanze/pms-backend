<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Tests\PmsTestCase;

/**
 * The legacy demo API identified callers by a bare user id (`X-User-Id`, `?as=`, or a bearer token that
 * was just an id). That resolver once ran on every request, so anyone could act as the admin on /api/v1.
 */
class ImpersonationTest extends PmsTestCase
{
    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_x_user_id_header_cannot_override_the_token_owner_on_v1(): void
    {
        $token = $this->tokenFor($this->w->stranger);

        $this->withToken($token)->withHeader('X-User-Id', $this->w->admin->id)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $this->w->stranger->id)
            ->assertJsonPath('data.role', 'user');
    }

    public function test_as_query_parameter_cannot_override_the_token_owner_on_v1(): void
    {
        $this->withToken($this->tokenFor($this->w->stranger))
            ->getJson('/api/v1/auth/me?as='.$this->w->admin->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->w->stranger->id);
    }

    public function test_a_bare_user_id_is_not_a_valid_bearer_token_on_v1(): void
    {
        $this->withToken($this->w->admin->id)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withHeader('X-User-Id', $this->w->admin->id)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_impersonation_header_does_not_widen_what_a_stranger_can_read(): void
    {
        $this->withToken($this->tokenFor($this->w->stranger))->withHeader('X-User-Id', $this->w->admin->id)
            ->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_unauthenticated_v1_requests_get_json_401_not_a_redirect(): void
    {
        $this->getJson('/api/v1/bootstrap')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->get('/api/v1/bootstrap')->assertUnauthorized();
    }

    public function test_the_legacy_demo_api_is_closed_outside_local_environments(): void
    {
        $this->app['env'] = 'production';

        $this->getJson('/api/demo-users')->assertNotFound();
        $this->withHeader('X-User-Id', $this->w->admin->id)->getJson('/api/bootstrap')->assertNotFound();
        $this->withHeader('X-User-Id', $this->w->admin->id)->putJson('/api/sync', [])->assertNotFound();
        $this->withHeader('X-User-Id', $this->w->admin->id)->postJson('/api/reset')->assertNotFound();
    }

    public function test_the_legacy_demo_api_still_works_locally(): void
    {
        $this->withHeader('X-User-Id', $this->w->admin->id)->getJson('/api/me')->assertOk();
    }

    public function test_passwordless_login_is_disabled_outside_local_environments(): void
    {
        $this->app['env'] = 'production';

        $this->postJson('/api/v1/auth/login', ['role' => 'admin'])->assertForbidden();
        $this->postJson('/api/v1/auth/login', ['userId' => $this->w->admin->id])->assertForbidden();
        $this->getJson('/api/v1/auth/demo-users')->assertNotFound();
    }

    public function test_password_login_works_and_wrong_passwords_fail(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $this->w->admin->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $this->postJson('/api/v1/auth/login', ['email' => $this->w->admin->email, 'password' => 'wrong'])
            ->assertStatus(422);
    }

    public function test_self_registration_cannot_choose_a_role(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mallory', 'email' => 'mallory@pms.test', 'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => 'admin',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mallory', 'email' => 'mallory@pms.test', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated()->assertJsonPath('data.user.role', 'user');
    }
}

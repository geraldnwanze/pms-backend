<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\RateLimiter;
use Tests\PmsTestCase;

class RateLimitTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    public function test_password_login_is_throttled_per_account_and_says_when_to_retry(): void
    {
        $body = ['email' => 'admin@pms.test', 'password' => 'wrong-password'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $body)->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', $body)
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_one_locked_account_does_not_lock_out_other_accounts(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'admin@pms.test', 'password' => 'nope']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'owner.a@pms.test', 'password' => 'password'])->assertOk();
    }

    public function test_even_the_right_password_is_refused_once_the_account_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'admin@pms.test', 'password' => 'nope']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@pms.test', 'password' => 'password'])->assertStatus(429);
    }

    public function test_registration_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/register', [
                'name' => "User {$i}", 'email' => "user{$i}@pms.test", 'password' => 'password123', 'password_confirmation' => 'password123',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Too many', 'email' => 'toomany@pms.test', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertStatus(429);
    }

    public function test_password_recovery_is_throttled_per_address(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@pms.test'])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@pms.test'])->assertStatus(429);
        // another address is unaffected
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'owner.a@pms.test'])->assertOk();
    }

    public function test_auto_debit_setup_is_throttled(): void
    {
        $this->as($this->w->tenantUser);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auto-debit/setup')->assertOk();
        }

        $this->postJson('/api/v1/auto-debit/setup')->assertStatus(429);
    }
}

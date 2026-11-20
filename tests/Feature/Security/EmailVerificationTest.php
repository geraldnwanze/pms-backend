<?php

namespace Tests\Feature\Security;

use App\Mail\VerifyEmailMail;
use App\Models\Notification;
use App\Models\PropertyMember;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\PmsTestCase;
use Tests\Support\World;

/**
 * Invitations are addressed to an email. Without proof of mailbox ownership, anyone could register a victim's
 * address and accept the invitation meant for them.
 */
class EmailVerificationTest extends PmsTestCase
{
    private function register(string $email = 'newbie@pms.test', string $name = 'Newbie')
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => $name, 'email' => $email, 'password' => 'password123', 'password_confirmation' => 'password123',
        ]);
    }

    /** @return array{0:string,1:string,2:string} id of the new account, its bearer token, and the emailed verify token */
    private function registered(string $email = 'newbie@pms.test'): array
    {
        $res = $this->register($email)->assertCreated();

        return [$res->json('data.user.id'), $res->json('data.token'), $res->json('data.verification.verify_token')];
    }

    /** Act as an account by id, re-reading it so changes made by earlier requests are seen. */
    private function asId(string $id): static
    {
        return $this->as(User::query()->findOrFail($id));
    }

    private function verify(string $email, string $token)
    {
        return $this->postJson('/api/v1/auth/verify-email', ['email' => $email, 'token' => $token]);
    }

    private function invite(string $email, string $role = 'manager'): string
    {
        return $this->as($this->w->ownerA)->postJson('/api/v1/properties/prop_a/invitations', ['email' => $email, 'role' => $role])->assertCreated()->json('data.id');
    }

    // ---- Registration ---------------------------------------------------------

    public function test_a_new_account_starts_unverified_and_is_emailed_a_link(): void
    {
        $res = $this->register()->assertCreated();

        $res->assertJsonPath('data.user.emailVerified', false);
        $this->assertNull(User::query()->find($res->json('data.user.id'))->emailVerifiedAt);
        Mail::assertQueued(VerifyEmailMail::class, fn ($m) => $m->hasTo('newbie@pms.test'));
        $this->assertStringContainsString('/verify-email?token=', $res->json('data.verification.verify_url'));
    }

    public function test_the_response_never_exposes_the_token_hash_or_expiry(): void
    {
        [, $bearer] = $this->registered();

        $body = $this->withToken($bearer)->getJson('/api/v1/auth/me')->assertOk()->getContent();

        $this->assertStringNotContainsString('emailVerifyTokenHash', $body);
        $this->assertStringNotContainsString('emailVerifyExpiresAt', $body);
    }

    public function test_only_a_hash_of_the_token_is_stored(): void
    {
        [$id, , $token] = $this->registered();

        $stored = DB::table('users')->where('id', $id)->value('emailVerifyTokenHash');

        $this->assertNotSame($token, $stored);
        $this->assertSame(hash('sha256', $token), $stored);
    }

    public function test_an_unverified_account_can_still_sign_in_and_get_started(): void
    {
        $this->register('early@pms.test')->assertCreated();

        $this->postJson('/api/v1/auth/login', ['email' => 'early@pms.test', 'password' => 'password123'])->assertOk();

        $user = User::query()->where('email', 'early@pms.test')->first();
        $this->as($user)->postJson('/api/v1/properties', [
            'name' => 'First Place', 'type' => 'residential', 'address' => '1 Road', 'city' => 'Lagos', 'state' => 'Lagos', 'country' => 'Nigeria',
        ])->assertCreated();
        $this->assertSame('owner', $user->fresh()->role);
    }

    // ---- The hole this closes --------------------------------------------------

    public function test_registering_someone_elses_address_does_not_hand_over_their_invitation(): void
    {
        $inviteId = $this->invite('victim@pms.test', 'manager');           // sent before the victim signs up

        [$attackerId] = $this->registered('victim@pms.test');               // attacker types the victim's address

        $this->assertNull(PropertyMember::query()->find($inviteId)->userId, 'the invitation must not be attached to an unverified account');
        $this->asId($attackerId)->getJson('/api/v1/invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->asId($attackerId)->postJson("/api/v1/invitations/{$inviteId}/accept")->assertForbidden();
        $this->assertSame('invited', PropertyMember::query()->find($inviteId)->status);
        $this->assertSame('user', User::query()->find($attackerId)->role);
    }

    public function test_an_unverified_account_cannot_accept_or_decline_an_invitation_made_to_it_directly(): void
    {
        $unverified = World::user('user', 'direct@pms.test', verified: false);
        $inviteId = $this->invite('direct@pms.test', 'agent');             // the account exists, so the invite is bound to it

        $this->as($unverified)->postJson("/api/v1/invitations/{$inviteId}/accept")->assertForbidden();
        $this->postJson("/api/v1/invitations/{$inviteId}/decline")->assertForbidden();
        $this->getJson('/api/v1/invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('invited', PropertyMember::query()->find($inviteId)->status);
    }

    public function test_an_unverified_account_gets_no_in_app_notification_naming_the_property(): void
    {
        $unverified = World::user('user', 'quiet@pms.test', verified: false);
        $verified = World::user('user', 'loud@pms.test');
        $this->invite('quiet@pms.test', 'agent');
        $this->invite('loud@pms.test', 'agent');

        $this->assertSame(0, Notification::query()->where('forUserId', $unverified->id)->count());
        $this->assertSame(1, Notification::query()->where('forUserId', $verified->id)->where('title', 'Property invitation')->count());
    }

    public function test_the_bootstrap_payload_hides_invitations_from_an_unverified_account(): void
    {
        $unverified = World::user('user', 'peek@pms.test', verified: false);
        $this->invite('peek@pms.test', 'agent');

        $payload = $this->as($unverified)->getJson('/api/v1/bootstrap')->assertOk()->json('data');

        $this->assertSame([], $payload['invitations']);
        $this->assertSame([], collect($payload['memberships'])->where('status', 'invited')->values()->all());
    }

    public function test_verifying_unlocks_the_waiting_invitation(): void
    {
        $inviteId = $this->invite('rightful@pms.test', 'manager');
        [$id, , $token] = $this->registered('rightful@pms.test');

        $this->verify('rightful@pms.test', $token)->assertOk();

        $this->asId($id)->getJson('/api/v1/invitations')->assertOk()->assertJsonCount(1, 'data');
        $this->asId($id)->postJson("/api/v1/invitations/{$inviteId}/accept")->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_an_account_invited_while_unverified_can_accept_after_verifying(): void
    {
        [$id, , $token] = $this->registered('later@pms.test');
        $inviteId = $this->invite('later@pms.test', 'agent');
        $this->asId($id)->postJson("/api/v1/invitations/{$inviteId}/accept")->assertForbidden();

        $this->verify('later@pms.test', $token)->assertOk();

        $this->asId($id)->postJson("/api/v1/invitations/{$inviteId}/accept")->assertOk();
        $this->assertSame('agent', User::query()->find($id)->role);
    }

    // ---- Verifying ------------------------------------------------------------

    public function test_the_link_verifies_the_account(): void
    {
        [$id, , $token] = $this->registered();

        $this->verify('newbie@pms.test', $token)->assertOk();

        $user = User::query()->find($id);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($user->emailVerifyTokenHash);
    }

    public function test_the_email_match_is_case_insensitive(): void
    {
        [$id, , $token] = $this->registered('mixed@pms.test');

        $this->verify('MIXED@PMS.TEST', $token)->assertOk();

        $this->assertTrue(User::query()->find($id)->hasVerifiedEmail());
    }

    public function test_a_link_works_only_once(): void
    {
        [, , $token] = $this->registered();
        $this->verify('newbie@pms.test', $token)->assertOk();

        $this->verify('newbie@pms.test', $token)->assertStatus(422);
    }

    public function test_every_failure_looks_the_same_so_addresses_cannot_be_probed(): void
    {
        [, , $token] = $this->registered();
        World::user('user', 'someone@pms.test', verified: false);

        $messages = collect([
            $this->verify('newbie@pms.test', 'wrong-token'),                     // wrong token
            $this->verify('nobody@pms.test', $token),                            // unknown address
            $this->verify('someone@pms.test', $token),                           // a real account, someone else's token
        ])->map(function ($r) {
            $r->assertStatus(422);

            return $r->json('errors.token.0');
        });

        $this->assertCount(1, $messages->unique());
    }

    public function test_one_accounts_token_cannot_verify_another_account(): void
    {
        [, , $tokenA] = $this->registered('alice@pms.test');
        [$bobId] = $this->registered('bob@pms.test');

        $this->verify('bob@pms.test', $tokenA)->assertStatus(422);

        $this->assertFalse(User::query()->find($bobId)->hasVerifiedEmail());
    }

    public function test_an_expired_link_is_refused(): void
    {
        [$id, , $token] = $this->registered();

        $this->travel(25)->hours();
        $this->verify('newbie@pms.test', $token)->assertStatus(422);

        $this->assertFalse(User::query()->find($id)->hasVerifiedEmail());
    }

    public function test_a_link_is_still_good_within_its_lifetime(): void
    {
        [, , $token] = $this->registered();

        $this->travel(23)->hours();

        $this->verify('newbie@pms.test', $token)->assertOk();
    }

    public function test_the_token_and_email_are_required(): void
    {
        $this->postJson('/api/v1/auth/verify-email', [])->assertStatus(422);
        $this->postJson('/api/v1/auth/verify-email', ['email' => 'a@pms.test'])->assertStatus(422);
        $this->postJson('/api/v1/auth/verify-email', ['email' => 'not-an-email', 'token' => 'x'])->assertStatus(422);
    }

    public function test_guessing_tokens_is_rate_limited(): void
    {
        $this->registered();

        for ($i = 0; $i < 5; $i++) {
            $this->verify('newbie@pms.test', "guess-{$i}")->assertStatus(422);
        }
        $this->verify('newbie@pms.test', 'guess-6')->assertStatus(429);
    }

    // ---- Resending ---------------------------------------------------------------

    public function test_resend_issues_a_new_link_and_retires_the_old_one(): void
    {
        [, $bearer, $old] = $this->registered();

        $new = $this->withToken($bearer)->postJson('/api/v1/auth/email/resend')->assertOk()->json('data.verify_token');

        $this->assertNotSame($old, $new);
        Mail::assertQueued(VerifyEmailMail::class, 2);
        $this->verify('newbie@pms.test', $old)->assertStatus(422);
        $this->verify('newbie@pms.test', $new)->assertOk();
    }

    public function test_resend_does_nothing_for_a_verified_account(): void
    {
        $this->as($this->w->ownerA)->postJson('/api/v1/auth/email/resend')->assertOk();

        Mail::assertNotQueued(VerifyEmailMail::class);
    }

    public function test_resend_needs_a_login_and_is_throttled(): void
    {
        $this->postJson('/api/v1/auth/email/resend')->assertUnauthorized();

        [, $bearer] = $this->registered();
        $this->withToken($bearer)->postJson('/api/v1/auth/email/resend')->assertOk();
        $this->withToken($bearer)->postJson('/api/v1/auth/email/resend')->assertOk();
        $this->withToken($bearer)->postJson('/api/v1/auth/email/resend')->assertStatus(429);
    }

    // ---- Other ways in --------------------------------------------------------------

    public function test_completing_a_password_reset_proves_the_mailbox(): void
    {
        $inviteId = $this->invite('forgot@pms.test', 'agent');
        [$id] = $this->registered('forgot@pms.test');
        $reset = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'forgot@pms.test'])->assertOk()->json('data.reset_token');

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'forgot@pms.test', 'token' => $reset, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->assertTrue(User::query()->find($id)->hasVerifiedEmail());
        $this->assertSame($id, PropertyMember::query()->find($inviteId)->userId);
    }

    public function test_nobody_can_self_verify_through_the_users_api(): void
    {
        $unverified = World::user('user', 'sneaky@pms.test', verified: false);

        $this->as($unverified)->putJson("/api/v1/users/{$unverified->id}", ['emailVerifiedAt' => now()->toIso8601String()]);

        $this->assertFalse($unverified->fresh()->hasVerifiedEmail());
    }

    public function test_an_admin_cannot_create_a_pre_verified_account_by_supplying_the_field(): void
    {
        $res = $this->as($this->w->admin)->postJson('/api/v1/users', [
            'name' => 'Made By Admin', 'email' => 'made@pms.test', 'role' => 'user', 'password' => 'password123',
            'emailVerifiedAt' => now()->toIso8601String(),
        ]);

        if ($res->status() === 201) {
            $this->assertFalse(User::query()->where('email', 'made@pms.test')->first()->hasVerifiedEmail());
        } else {
            $this->assertSame(422, $res->status()); // rejected outright is fine too
        }
    }

    public function test_changing_an_accounts_email_requires_proving_the_new_one(): void
    {
        $user = $this->w->ownerB;
        $this->assertTrue($user->hasVerifiedEmail());

        $this->as($this->w->admin)->putJson("/api/v1/users/{$user->id}", ['email' => 'new.address@pms.test'])->assertOk();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertNull($user->fresh()->emailVerifyTokenHash);
    }

    public function test_updating_other_fields_keeps_the_account_verified(): void
    {
        $this->as($this->w->admin)->putJson("/api/v1/users/{$this->w->ownerB->id}", ['title' => 'Chairman'])->assertOk();

        $this->assertTrue($this->w->ownerB->fresh()->hasVerifiedEmail());
    }

    public function test_an_unverified_account_is_not_shown_memberships_that_are_only_tied_to_its_email(): void
    {
        $unverified = World::user('user', 'claims@pms.test', verified: false);
        // a past, userless row addressed to that email (e.g. someone who was removed) — it is not this account's history
        PropertyMember::query()->create([
            'id' => 'mem_old', 'propertyId' => 'prop_a', 'userId' => null, 'email' => 'claims@pms.test', 'role' => 'manager', 'status' => 'revoked',
        ]);

        $ids = collect($this->as($unverified)->getJson('/api/v1/bootstrap')->assertOk()->json('data.memberships'))->pluck('id');

        $this->assertNotContains('mem_old', $ids);
    }

    public function test_the_users_api_ignores_attempts_to_set_verification_state_even_from_an_admin(): void
    {
        $target = World::user('user', 'target@pms.test', verified: false);

        $this->as($this->w->admin)->putJson("/api/v1/users/{$target->id}", [
            'emailVerifiedAt' => now()->toIso8601String(),
            'emailVerifyTokenHash' => hash('sha256', 'known-token'),
            'emailVerifyExpiresAt' => now()->addDay()->toIso8601String(),
        ]);

        $this->assertFalse($target->fresh()->hasVerifiedEmail());
        $this->verify('target@pms.test', 'known-token')->assertStatus(422);
    }

    public function test_the_user_service_itself_refuses_to_write_verification_state(): void
    {
        // The HTTP layer already whitelists fields; this guards the service in case that whitelist ever grows.
        $target = World::user('user', 'direct.write@pms.test', verified: false);

        app(UserService::class)->update($this->w->admin, $target->id, [
            'emailVerifiedAt' => now()->toIso8601String(),
            'emailVerifyTokenHash' => hash('sha256', 'forged'),
            'emailVerifyExpiresAt' => now()->addDay()->toIso8601String(),
        ]);

        $this->assertFalse($target->fresh()->hasVerifiedEmail());
        $this->verify('direct.write@pms.test', 'forged')->assertStatus(422);
    }

    public function test_the_user_service_never_creates_a_pre_verified_account(): void
    {
        $created = app(UserService::class)->create($this->w->admin, [
            'name' => 'Direct', 'email' => 'direct.create@pms.test', 'role' => 'user', 'password' => 'password123',
            'emailVerifiedAt' => now()->toIso8601String(), 'emailVerifyTokenHash' => hash('sha256', 'forged'),
        ]);

        $this->assertFalse($created->fresh()->hasVerifiedEmail());
        $this->assertNull($created->fresh()->emailVerifyTokenHash);
    }
}

<?php

namespace Tests\Feature\Security;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\PmsTestCase;
use Tests\Support\World;

/** Forgot-password and reset: the token is a one-shot, short-lived, per-account secret, and the flow gives nothing away. */
class PasswordResetTest extends PmsTestCase
{
    private const EMAIL = 'manager.a@pms.test';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        RateLimiter::clear('');
    }

    private function forgot(string $email = self::EMAIL)
    {
        return $this->postJson('/api/v1/auth/forgot-password', ['email' => $email]);
    }

    private function token(string $email = self::EMAIL): string
    {
        return $this->forgot($email)->assertOk()->json('data.reset_token');
    }

    private function reset(string $token, string $password = 'a-brand-new-pass', array $over = [])
    {
        return $this->postJson('/api/v1/auth/reset-password', $over + [
            'email' => self::EMAIL, 'token' => $token, 'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    /** The recovery throttle is 3 a minute per account; a test that takes several steps starts each with a clean slate. */
    private function fresh(): void
    {
        Cache::flush();
    }

    private function canSignInWith(string $password, string $email = self::EMAIL): bool
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->status() === 200;
    }

    // ---- Asking for a link -------------------------------------------------------

    public function test_a_registered_address_is_emailed_a_link_carrying_the_token_and_address(): void
    {
        $res = $this->forgot()->assertOk();

        $token = $res->json('data.reset_token');
        $this->assertNotEmpty($token);
        $this->assertStringContainsString('/reset-password?token='.$token, $res->json('data.reset_url'));
        $this->assertStringContainsString('email='.urlencode(self::EMAIL), $res->json('data.reset_url'));

        Mail::assertQueued(PasswordResetMail::class, fn ($m) => $m->hasTo(self::EMAIL));
        Mail::assertQueuedCount(1);
    }

    public function test_an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        $known = $this->forgot();
        $unknown = $this->forgot('nobody@pms.test');

        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame(200, $unknown->status());
        $this->assertNull($unknown->json('data'));
        Mail::assertQueuedCount(1);                                   // only the known one
    }

    public function test_outside_development_the_response_never_contains_the_token(): void
    {
        $this->app['env'] = 'production';

        $res = $this->forgot()->assertOk();

        $this->assertNull($res->json('data'));
        Mail::assertQueued(PasswordResetMail::class);                 // it still goes by email
    }

    public function test_the_address_is_matched_without_regard_to_case(): void
    {
        $this->assertNotEmpty($this->token('MANAGER.A@PMS.TEST'));
    }

    public function test_the_address_must_be_a_valid_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', [])->assertStatus(422);
        $this->forgot('not-an-email')->assertStatus(422);
    }

    public function test_only_a_hash_of_the_token_is_stored(): void
    {
        $token = $this->token();

        $row = DB::table('password_reset_tokens')->where('email', self::EMAIL)->first();

        $this->assertNotNull($row);
        $this->assertNotSame($token, $row->token);
        $this->assertTrue(Hash::check($token, $row->token));
    }

    public function test_asking_again_replaces_the_earlier_link(): void
    {
        $first = $this->token();
        $second = $this->token();

        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', self::EMAIL)->count());
        $this->fresh();
        $this->reset($first)->assertStatus(422);
        $this->reset($second)->assertOk();
    }

    // ---- Using it -----------------------------------------------------------------

    public function test_a_valid_token_sets_the_new_password_and_the_old_one_stops_working(): void
    {
        $token = $this->token();

        $this->reset($token, 'a-brand-new-pass')->assertOk();

        $this->assertTrue($this->canSignInWith('a-brand-new-pass'));
        $this->assertFalse($this->canSignInWith('password'));
        $this->assertTrue(Hash::check('a-brand-new-pass', User::query()->where('email', self::EMAIL)->value('password')));
    }

    public function test_a_token_works_once(): void
    {
        $token = $this->token();

        $this->reset($token, 'a-brand-new-pass')->assertOk();
        $this->reset($token, 'another-new-pass')->assertStatus(422);

        $this->assertFalse($this->canSignInWith('another-new-pass'));
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', self::EMAIL)->count());
    }

    public function test_resetting_signs_out_every_other_session(): void
    {
        $session = $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => 'password'])->json('data.token');
        $this->assertSame(200, $this->withToken($session)->getJson('/api/v1/properties')->status());
        $this->app['auth']->forgetGuards();

        $this->reset($this->token())->assertOk();

        $this->assertSame(401, $this->withToken($session)->getJson('/api/v1/properties')->status());
    }

    public function test_resetting_does_not_sign_you_in(): void
    {
        $res = $this->reset($this->token())->assertOk();

        $this->assertNull($res->json('data.token'));
        $this->assertNull($res->json('token'));
    }

    public function test_resetting_also_confirms_the_address_because_the_link_went_to_that_inbox(): void
    {
        $user = World::user('user', 'fresh@pms.test', verified: false);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'fresh@pms.test', 'token' => $this->token('fresh@pms.test'), 'password' => 'a-brand-new-pass', 'password_confirmation' => 'a-brand-new-pass',
        ])->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    // ---- Bad tokens ---------------------------------------------------------------

    public function test_a_wrong_or_missing_token_changes_nothing(): void
    {
        $this->token();
        $this->fresh();

        foreach (['wrong-token', str_repeat('a', 64), ''] as $bad) {
            $this->reset($bad)->assertStatus(422);
        }
        $this->fresh();
        $this->assertTrue($this->canSignInWith('password'));
        $this->assertFalse($this->canSignInWith('a-brand-new-pass'));
    }

    public function test_one_accounts_token_cannot_reset_another_accounts_password(): void
    {
        $mine = $this->token(self::EMAIL);
        $this->token('owner.a@pms.test');

        $this->reset($mine, 'a-brand-new-pass', ['email' => 'owner.a@pms.test'])->assertStatus(422);

        $this->assertTrue($this->canSignInWith('password', 'owner.a@pms.test'));
        $this->assertTrue($this->canSignInWith('password', self::EMAIL));
    }

    public function test_an_unknown_address_is_refused_like_a_bad_token(): void
    {
        $known = $this->reset('wrong-token');
        $unknown = $this->reset('wrong-token', 'a-brand-new-pass', ['email' => 'nobody@pms.test']);

        $this->assertSame(422, $unknown->status());
        $this->assertSame($known->json('errors.email'), $unknown->json('errors.email'), 'the wording must not reveal whether the address exists');
    }

    public function test_the_token_expires_after_an_hour(): void
    {
        $token = $this->token();

        Carbon::setTestNow(now()->addMinutes(61));
        $this->reset($token)->assertStatus(422);

        $this->assertTrue($this->canSignInWith('password'));
    }

    public function test_the_token_still_works_just_inside_the_hour(): void
    {
        $token = $this->token();

        Carbon::setTestNow(now()->addMinutes(59));
        $this->reset($token)->assertOk();
    }

    // ---- Bad passwords --------------------------------------------------------------

    public function test_a_weak_or_mismatched_password_is_refused_and_the_token_survives(): void
    {
        $token = $this->token();
        $this->fresh();

        $this->reset($token, 'short')->assertStatus(422)->assertJsonValidationErrors('password');
        $this->reset($token, 'a-brand-new-pass', ['password_confirmation' => 'different-pass'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->fresh();
        $this->postJson('/api/v1/auth/reset-password', ['email' => self::EMAIL, 'token' => $token])->assertStatus(422);
        $this->fresh();

        $this->assertTrue($this->canSignInWith('password'));
        $this->reset($token)->assertOk();          // a typo didn't burn the link
    }

    // ---- Guessing ---------------------------------------------------------------------

    public function test_guessing_tokens_is_throttled_per_account(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->reset("guess-{$i}")->assertStatus(422);
        }

        $this->reset('guess-4')->assertStatus(429)->assertHeader('Retry-After');
        $this->reset('guess-5', 'a-brand-new-pass', ['email' => 'owner.a@pms.test'])->assertStatus(422);    // another account is unaffected
    }
}

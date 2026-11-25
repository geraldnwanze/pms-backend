<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\CaptchaService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\PmsTestCase;

/** The "I'm not a robot" challenge on sign-in, register, forgot-password and reset-password: off unless switched on, then refused unless Cloudflare confirms it. */
class CaptchaTest extends PmsTestCase
{
    private const VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private function switchOn(): void
    {
        config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => 'site-key-public', 'services.turnstile.secret_key' => 'secret-key-private']);
    }

    /** The shared test setup fakes every HTTP call as Paystack; these tests talk to Cloudflare, so start from a clean fake. */
    private function fakeHttp(mixed $stubs = null): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    private function siteverify(bool $success = true): void
    {
        $this->fakeHttp([self::VERIFY => Http::response($success ? ['success' => true] : ['success' => false, 'error-codes' => ['invalid-input-response']])]);
    }

    private function login(array $extra = [], string $password = 'password')
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $this->w->managerA->email, 'password' => $password] + $extra);
    }

    private function register(array $extra = [])
    {
        return $this->postJson('/api/v1/auth/register', ['name' => 'New Person', 'email' => 'new.person@pms.test', 'password' => 'a-long-secret', 'password_confirmation' => 'a-long-secret'] + $extra);
    }

    private function reset(array $extra = [])
    {
        return $this->postJson('/api/v1/auth/reset-password', $extra + ['email' => $this->w->managerA->email, 'token' => 'wrong-token', 'password' => 'a-long-secret', 'password_confirmation' => 'a-long-secret']);
    }

    private function forgot(array $extra = [])
    {
        return $this->postJson('/api/v1/auth/forgot-password', ['email' => $this->w->managerA->email] + $extra);
    }

    // ---- off (the default) ------------------------------------------------------------------------------------

    public function test_nothing_is_asked_for_while_the_challenge_is_off(): void
    {
        $this->fakeHttp();

        $this->login()->assertOk();
        $this->register()->assertCreated();
        $this->forgot()->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_settings_say_it_is_off_and_hide_the_key_even_if_one_is_configured(): void
    {
        config(['services.turnstile.site_key' => 'site-key-public', 'services.turnstile.secret_key' => 'secret-key-private']);

        $this->getJson('/api/v1/auth/captcha')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.siteKey', null);
    }

    // ---- the settings the page reads -----------------------------------------------------------------------------

    public function test_the_settings_give_the_page_its_public_key_and_never_the_secret(): void
    {
        $this->switchOn();

        $response = $this->getJson('/api/v1/auth/captcha')->assertOk();

        $response->assertJsonPath('data.enabled', true)->assertJsonPath('data.siteKey', 'site-key-public');
        $this->assertStringNotContainsString('secret-key-private', $response->getContent());
    }

    public function test_the_settings_need_no_login(): void
    {
        $this->getJson('/api/v1/auth/captcha')->assertOk();
    }

    // ---- on: no token --------------------------------------------------------------------------------------------

    public function test_each_page_refuses_a_request_without_a_token_and_does_nothing(): void
    {
        $this->switchOn();
        $this->fakeHttp();
        Mail::fake();
        $users = User::query()->count();

        $this->login()->assertUnprocessable()->assertJsonPath('errors.captchaToken.0', 'Please confirm you are not a robot.');
        $this->register()->assertUnprocessable()->assertJsonPath('errors.captchaToken.0', 'Please confirm you are not a robot.');
        $this->forgot()->assertUnprocessable()->assertJsonPath('errors.captchaToken.0', 'Please confirm you are not a robot.');
        $this->reset()->assertUnprocessable()->assertJsonPath('errors.captchaToken.0', 'Please confirm you are not a robot.');

        Http::assertNothingSent();                                         // no point asking Cloudflare about nothing
        Mail::assertNothingQueued();
        $this->assertSame($users, User::query()->count());
        $this->assertSame(0, $this->w->managerA->tokens()->count(), 'nobody was signed in');
    }

    public function test_an_empty_or_non_text_token_counts_as_no_token(): void
    {
        $this->switchOn();
        $this->fakeHttp();

        $this->login(['captchaToken' => ''])->assertUnprocessable()->assertJsonValidationErrors('captchaToken');
        $this->login(['captchaToken' => ['a', 'b']])->assertUnprocessable()->assertJsonValidationErrors('captchaToken');
        $this->login(['captchaToken' => 12345])->assertUnprocessable()->assertJsonValidationErrors('captchaToken');

        Http::assertNothingSent();
    }

    public function test_the_challenge_is_checked_before_the_password_so_it_reveals_nothing_about_it(): void
    {
        $this->switchOn();

        $right = $this->login()->json();
        $wrong = $this->login([], 'not-the-password')->json();

        $this->assertSame($right, $wrong);                                // same answer whether or not the password was right
        $this->assertArrayHasKey('captchaToken', $wrong['errors']);
        $this->assertArrayNotHasKey('email', $wrong['errors']);
    }

    // ---- on: a token ------------------------------------------------------------------------------------------------

    public function test_a_token_cloudflare_rejects_is_refused_and_does_nothing(): void
    {
        $this->switchOn();
        $this->siteverify(false);
        Mail::fake();
        $users = User::query()->count();

        $this->login(['captchaToken' => 'bad-token'])->assertUnprocessable()->assertJsonPath('errors.captchaToken.0', 'We could not verify that you are not a robot. Please try again.');
        $this->register(['captchaToken' => 'bad-token'])->assertUnprocessable()->assertJsonValidationErrors('captchaToken');
        $this->forgot(['captchaToken' => 'bad-token'])->assertUnprocessable()->assertJsonValidationErrors('captchaToken');
        $this->reset(['captchaToken' => 'bad-token'])->assertUnprocessable()->assertJsonValidationErrors('captchaToken');

        Mail::assertNothingQueued();
        $this->assertSame($users, User::query()->count());
        $this->assertSame(0, $this->w->managerA->tokens()->count());
    }

    public function test_a_token_cloudflare_accepts_lets_each_request_through(): void
    {
        $this->switchOn();
        $this->siteverify(true);

        $this->login(['captchaToken' => 'good-token'])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
        $this->register(['captchaToken' => 'good-token'])->assertCreated();
        $this->forgot(['captchaToken' => 'good-token'])->assertOk();
    }

    public function test_the_secret_the_token_and_the_visitors_address_go_to_cloudflare_and_nothing_else_does(): void
    {
        $this->switchOn();
        $this->siteverify(true);

        $this->login(['captchaToken' => 'good-token'])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return $request->url() === self::VERIFY
                && $request->method() === 'POST'
                && $request->isForm()
                && $request['secret'] === 'secret-key-private'
                && $request['response'] === 'good-token'
                && $request['remoteip'] !== null
                && ! isset($request['password']) && ! isset($request['email']);        // the visitor's credentials never leave
        });
    }

    public function test_a_valid_challenge_does_not_hide_a_wrong_password(): void
    {
        $this->switchOn();
        $this->siteverify(true);

        $this->login(['captchaToken' => 'good-token'], 'not-the-password')
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    // ---- on: when Cloudflare cannot confirm -----------------------------------------------------------------------------

    public function test_it_fails_closed_when_cloudflare_cannot_be_reached(): void
    {
        $this->switchOn();
        $this->fakeHttp(fn () => throw new ConnectionException('timed out'));
        Log::spy();

        $this->login(['captchaToken' => 'good-token'])->assertUnprocessable()->assertJsonValidationErrors('captchaToken');

        $this->assertSame(0, $this->w->managerA->tokens()->count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_it_fails_closed_when_cloudflare_answers_with_an_error(): void
    {
        $this->switchOn();
        $this->fakeHttp([self::VERIFY => Http::response('Bad gateway', 502)]);

        $this->login(['captchaToken' => 'good-token'])->assertUnprocessable()->assertJsonValidationErrors('captchaToken');
    }

    public function test_it_fails_closed_when_the_secret_is_missing_and_says_so_in_the_log(): void
    {
        $this->switchOn();
        config(['services.turnstile.secret_key' => '']);
        $this->fakeHttp();
        Log::spy();

        $this->login(['captchaToken' => 'good-token'])->assertUnprocessable()->assertJsonValidationErrors('captchaToken');

        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->once();
    }

    // ---- what it leaves alone ------------------------------------------------------------------------------------

    public function test_the_local_passwordless_demo_sign_in_is_not_asked_for_a_token(): void
    {
        $this->switchOn();

        $this->postJson('/api/v1/auth/login', ['userId' => $this->w->managerA->id])->assertOk();
        $this->postJson('/api/v1/auth/login', ['role' => 'admin'])->assertStatus(200);
    }

    public function test_adding_a_role_does_not_get_a_password_sign_in_past_the_challenge(): void
    {
        $this->switchOn();

        $this->login(['role' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('captchaToken');
    }

    public function test_email_verification_is_not_asked_for_one(): void
    {
        $this->switchOn();

        $verify = $this->postJson('/api/v1/auth/verify-email', ['email' => $this->w->managerA->email, 'token' => 'wrong']);

        $this->assertArrayNotHasKey('captchaToken', $verify->json('errors') ?? []);
    }

    public function test_a_password_reset_with_a_good_tick_gets_as_far_as_checking_the_emailed_token(): void
    {
        $this->switchOn();
        $this->siteverify();

        // Past the challenge, the wrong emailed token is what refuses it — not the robot check.
        $response = $this->reset(['captchaToken' => 'good-token']);

        $response->assertUnprocessable();
        $this->assertArrayNotHasKey('captchaToken', $response->json('errors') ?? []);
    }

    public function test_a_valid_emailed_token_alone_does_not_reset_a_password_while_the_challenge_is_on(): void
    {
        $this->switchOn();
        $this->siteverify();
        $token = $this->forgot(['captchaToken' => 'good-token'])->assertOk()->json('data.reset_token');

        $this->reset(['token' => $token, 'password' => 'brand-new-secret', 'password_confirmation' => 'brand-new-secret'])
            ->assertUnprocessable()->assertJsonValidationErrors('captchaToken');
        $this->assertFalse(Hash::check('brand-new-secret', User::query()->find($this->w->managerA->id)->password));

        $this->reset(['token' => $token, 'password' => 'brand-new-secret', 'password_confirmation' => 'brand-new-secret', 'captchaToken' => 'good-token'])->assertOk();
        $this->assertTrue(Hash::check('brand-new-secret', User::query()->find($this->w->managerA->id)->password));
    }

    public function test_a_signed_in_user_is_not_asked_again_for_anything_else(): void
    {
        $this->switchOn();

        $this->as($this->w->managerA)->getJson('/api/v1/bootstrap')->assertOk();
        $this->as($this->w->managerA)->getJson('/api/v1/modules')->assertOk();
    }

    // ---- the verification itself ---------------------------------------------------------------------------------

    public function test_only_an_explicit_success_from_cloudflare_counts(): void
    {
        $this->switchOn();
        $service = app(CaptchaService::class);

        foreach ([
            ['body' => ['success' => true], 'status' => 200, 'expected' => true],
            ['body' => ['success' => false], 'status' => 200, 'expected' => false],
            ['body' => [], 'status' => 200, 'expected' => false],                                        // no verdict at all
            ['body' => ['error-codes' => ['missing-input-response']], 'status' => 200, 'expected' => false],
            ['body' => ['success' => 'true'], 'status' => 200, 'expected' => false],                      // text is not a yes
            ['body' => ['success' => 1], 'status' => 200, 'expected' => false],
            ['body' => ['success' => true], 'status' => 500, 'expected' => false],                       // an error status wins over the body
            ['body' => ['success' => true], 'status' => 403, 'expected' => false],
        ] as $case) {
            $this->fakeHttp([self::VERIFY => Http::response($case['body'], $case['status'])]);

            $this->assertSame($case['expected'], $service->verify('a-token', '203.0.113.9'), json_encode($case));
        }
    }

    public function test_the_service_will_not_even_ask_cloudflare_about_an_empty_token(): void
    {
        $this->switchOn();
        $this->siteverify(true);
        $service = app(CaptchaService::class);

        $this->assertFalse($service->verify(null));
        $this->assertFalse($service->verify(''));
        $this->assertFalse($service->verify('   '));

        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\PmsTestCase;
use Tests\Support\World;

/** `pms:make-superadmin` is the only way to get a superadmin: the app's own screens cannot make one. */
class MakeSuperAdminTest extends PmsTestCase
{
    private function make(string $email, array $options = []): int
    {
        return Artisan::call('pms:make-superadmin', ['email' => $email] + $options + ['--no-interaction' => true]);
    }

    // ---- creating one -------------------------------------------------------------------------------------

    public function test_it_creates_a_superadmin_who_can_sign_in_and_switch_modules(): void
    {
        $code = $this->make('  Boss@Example.TEST ', ['--name' => 'Chidi Eze', '--password' => 'a-long-secret']);

        $this->assertSame(0, $code);
        $user = User::query()->where('email', 'boss@example.test')->firstOrFail();      // trimmed and lower-cased
        $this->assertSame('superadmin', $user->role);
        $this->assertSame('Chidi Eze', $user->name);
        $this->assertSame('active', $user->status);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('a-long-secret', $user->password));
        $this->assertNotSame('a-long-secret', $user->getRawOriginal('password'));

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'boss@example.test', 'password' => 'a-long-secret'])->assertOk()->json('data.token');
        $this->withToken($token)->putJson('/api/v1/modules/planner', ['enabled' => false])->assertOk();
    }

    public function test_it_records_who_made_it_in_the_audit_trail_without_the_password(): void
    {
        $this->make('boss@example.test', ['--name' => 'Chidi', '--password' => 'a-long-secret']);

        $row = AuditLog::query()->where('entity', 'User')->where('summary', 'like', '%superadmin boss@example.test%')->firstOrFail();
        $this->assertSame('system', $row->userId);
        $this->assertSame('Created superadmin boss@example.test from the command line', $row->summary);
        $this->assertSame('Command line', $row->device);                           // not a made-up address and browser
        $this->assertNull($row->ip);
        $this->assertStringNotContainsString('a-long-secret', json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_it_never_prints_the_password(): void
    {
        $this->make('boss@example.test', ['--name' => 'Chidi', '--password' => 'a-long-secret']);

        $output = Artisan::output();                                              // reading it empties it, so read it once
        $this->assertStringNotContainsString('a-long-secret', $output);
        $this->assertStringContainsString('Created superadmin boss@example.test.', $output);
    }

    public function test_run_by_hand_it_asks_for_the_name_and_the_password_twice(): void
    {
        $this->artisan('pms:make-superadmin', ['email' => 'boss@example.test'])
            ->expectsQuestion('Name', 'Chidi Eze')
            ->expectsQuestion('Password (at least 8 characters)', 'a-long-secret')
            ->expectsQuestion('Confirm the password', 'a-long-secret')
            ->expectsOutputToContain('Created superadmin boss@example.test.')
            ->assertSuccessful();

        $this->assertSame('superadmin', User::query()->where('email', 'boss@example.test')->value('role'));
    }

    public function test_passwords_that_do_not_match_create_nothing(): void
    {
        $this->artisan('pms:make-superadmin', ['email' => 'boss@example.test', '--name' => 'Chidi'])
            ->expectsQuestion('Password (at least 8 characters)', 'a-long-secret')
            ->expectsQuestion('Confirm the password', 'something-else')
            ->expectsOutputToContain('The passwords do not match.')
            ->assertFailed();

        $this->assertNull(User::query()->where('email', 'boss@example.test')->first());
    }

    public function test_a_new_account_needs_a_name_and_a_long_enough_password_and_a_real_email(): void
    {
        $this->assertSame(1, $this->make('boss@example.test', ['--password' => 'a-long-secret']));                   // no name, and not asked
        $this->assertSame(1, $this->make('boss@example.test', ['--name' => 'Chidi']));                               // no password
        $this->assertSame(1, $this->make('boss@example.test', ['--name' => 'Chidi', '--password' => 'short']));      // too short
        $this->assertSame(1, $this->make('not-an-email', ['--name' => 'Chidi', '--password' => 'a-long-secret']));

        $this->assertSame(0, User::query()->where('role', 'superadmin')->count());
    }

    // ---- promoting an existing account --------------------------------------------------------------------

    public function test_it_promotes_an_existing_admin_and_says_what_they_were(): void
    {
        $code = $this->make($this->w->admin->email, ['--force' => true]);

        $this->assertSame(0, $code);
        $this->assertSame('superadmin', $this->w->admin->fresh()->role);
        $this->assertStringContainsString('is now a superadmin (was admin).', Artisan::output());
        $this->assertTrue(AuditLog::query()->where('summary', "Made {$this->w->admin->email} a superadmin (was admin) from the command line")->where('userId', 'system')->where('device', 'Command line')->exists());
    }

    public function test_it_promotes_a_plain_registered_account(): void
    {
        $this->assertSame(0, $this->make($this->w->stranger->email, ['--force' => true]));

        $this->assertSame('superadmin', $this->w->stranger->fresh()->role);
    }

    public function test_it_asks_before_promoting_and_changes_nothing_on_no(): void
    {
        $this->artisan('pms:make-superadmin', ['email' => $this->w->admin->email])
            ->expectsConfirmation("Make {$this->w->admin->email} (admin) a superadmin?", 'no')
            ->expectsOutputToContain('Cancelled. Nothing changed.')
            ->assertFailed();

        $this->assertSame('admin', $this->w->admin->fresh()->role);
    }

    public function test_it_promotes_when_the_answer_is_yes(): void
    {
        $this->artisan('pms:make-superadmin', ['email' => $this->w->admin->email])
            ->expectsConfirmation("Make {$this->w->admin->email} (admin) a superadmin?", 'yes')
            ->assertSuccessful();

        $this->assertSame('superadmin', $this->w->admin->fresh()->role);
    }

    public function test_running_it_again_on_a_superadmin_changes_nothing(): void
    {
        $this->make($this->w->admin->email, ['--force' => true]);
        $audits = AuditLog::query()->count();

        $this->assertSame(0, $this->make($this->w->admin->email, ['--force' => true]));

        $this->assertStringContainsString('is already a superadmin. Nothing changed.', Artisan::output());
        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_it_will_not_promote_an_account_that_belongs_to_a_property_or_a_tenancy(): void
    {
        foreach (['ownerA', 'managerA', 'financeA', 'tenantUser'] as $who) {
            $this->assertSame(1, $this->make($this->w->{$who}->email, ['--force' => true]), $who);
            $this->assertStringContainsString('belongs to a property or a tenancy', Artisan::output(), $who);
            $this->assertNotSame('superadmin', $this->w->{$who}->fresh()->role, $who);
        }

        $linked = World::user('user', 'linked@pms.test');
        $linked->update(['tenantId' => 'ten_ada']);
        $this->assertSame(1, $this->make('linked@pms.test', ['--force' => true]));
        $this->assertSame('user', $linked->fresh()->role);
    }

    public function test_it_will_not_promote_a_deactivated_account(): void
    {
        $this->w->stranger->update(['status' => 'inactive']);

        $this->assertSame(1, $this->make($this->w->stranger->email, ['--force' => true]));

        $this->assertStringContainsString('is deactivated', Artisan::output());
        $this->assertSame('user', $this->w->stranger->fresh()->role);
    }

    public function test_a_promoted_account_can_switch_modules_and_an_admin_still_cannot(): void
    {
        $this->make($this->w->stranger->email, ['--force' => true]);

        $this->as($this->w->stranger->fresh())->putJson('/api/v1/modules/planner', ['enabled' => false])->assertOk();
        $this->as($this->w->ownerA)->putJson('/api/v1/modules/planner', ['enabled' => true])->assertForbidden();
    }

    public function test_the_screens_still_cannot_make_a_superadmin(): void
    {
        $this->as($this->w->admin)->putJson("/api/v1/users/{$this->w->stranger->id}", ['role' => 'superadmin'])->assertUnprocessable();
        $this->as($this->w->admin)->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@pms.test', 'role' => 'superadmin', 'password' => 'a-long-secret'])->assertUnprocessable();

        $this->assertSame(0, User::query()->where('role', 'superadmin')->count());
    }

    // ---- taking it away -----------------------------------------------------------------------------------

    /** Two superadmins, so that one of them can be revoked. */
    private function twoSuperadmins(): array
    {
        $this->make($this->w->admin->email, ['--force' => true]);
        $this->make($this->w->stranger->email, ['--force' => true]);

        return [$this->w->admin->fresh(), $this->w->stranger->fresh()];
    }

    public function test_it_takes_the_role_away_and_the_account_goes_back_to_admin(): void
    {
        [$keep, $drop] = $this->twoSuperadmins();

        $code = $this->make($drop->email, ['--revoke' => true, '--force' => true]);

        $this->assertSame(0, $code);
        $this->assertSame('admin', $drop->fresh()->role);
        $this->assertSame('superadmin', $keep->fresh()->role);
        $this->assertStringContainsString('is no longer a superadmin (now admin).', Artisan::output());
    }

    public function test_it_can_send_the_account_back_to_a_plain_user_instead(): void
    {
        [, $drop] = $this->twoSuperadmins();

        $this->assertSame(0, $this->make($drop->email, ['--revoke' => true, '--to' => 'user', '--force' => true]));

        $this->assertSame('user', $drop->fresh()->role);
    }

    public function test_the_revoked_account_loses_the_module_switches_at_once_but_keeps_its_sign_in(): void
    {
        [, $drop] = $this->twoSuperadmins();
        $token = $drop->createToken('t')->plainTextToken;                      // signed in before it was revoked
        $this->withToken($token)->putJson('/api/v1/modules/planner', ['enabled' => false])->assertOk();

        $this->make($drop->email, ['--revoke' => true, '--force' => true]);
        $this->app['auth']->forgetGuards();                                    // a test keeps the signed-in user between requests; a real server reads them afresh each time

        $this->withToken($token)->putJson('/api/v1/modules/planner', ['enabled' => true])->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/modules')->assertOk();      // still signed in, can still read what is on
        $this->assertSame('active', $drop->fresh()->status);
        $this->assertTrue($drop->fresh()->isAdmin());
    }

    public function test_it_records_the_revoking_in_the_audit_trail(): void
    {
        [, $drop] = $this->twoSuperadmins();

        $this->make($drop->email, ['--revoke' => true, '--force' => true]);

        $this->assertTrue(AuditLog::query()->where('userId', 'system')->where('summary', "Removed superadmin from {$drop->email} (now admin) from the command line")->where('device', 'Command line')->exists());
    }

    public function test_it_asks_before_revoking_and_changes_nothing_on_no(): void
    {
        [, $drop] = $this->twoSuperadmins();

        $this->artisan('pms:make-superadmin', ['email' => $drop->email, '--revoke' => true])
            ->expectsConfirmation("Take the superadmin role away from {$drop->email} (they become admin)?", 'no')
            ->expectsOutputToContain('Cancelled. Nothing changed.')
            ->assertFailed();

        $this->assertSame('superadmin', $drop->fresh()->role);
    }

    public function test_it_revokes_when_the_answer_is_yes(): void
    {
        [, $drop] = $this->twoSuperadmins();

        $this->artisan('pms:make-superadmin', ['email' => $drop->email, '--revoke' => true])
            ->expectsConfirmation("Take the superadmin role away from {$drop->email} (they become admin)?", 'yes')
            ->assertSuccessful();

        $this->assertSame('admin', $drop->fresh()->role);
    }

    public function test_it_will_not_revoke_the_only_superadmin(): void
    {
        $this->make($this->w->admin->email, ['--force' => true]);

        $code = $this->make($this->w->admin->email, ['--revoke' => true, '--force' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('is the only superadmin', Artisan::output());
        $this->assertSame('superadmin', $this->w->admin->fresh()->role);
    }

    public function test_the_last_one_can_be_replaced_by_making_the_new_one_first(): void
    {
        $this->make($this->w->admin->email, ['--force' => true]);
        $this->make('second@example.test', ['--name' => 'Second', '--password' => 'a-long-secret']);

        $this->assertSame(0, $this->make($this->w->admin->email, ['--revoke' => true, '--force' => true]));

        $this->assertSame(1, User::query()->where('role', 'superadmin')->count());
    }

    public function test_it_only_revokes_a_superadmin_that_exists(): void
    {
        $this->twoSuperadmins();

        $this->assertSame(1, $this->make($this->w->ownerA->email, ['--revoke' => true, '--force' => true]));
        $this->assertStringContainsString('is not a superadmin', Artisan::output());
        $this->assertSame(1, $this->make('nobody@example.test', ['--revoke' => true, '--force' => true]));
        $this->assertStringContainsString('nobody@example.test is not a superadmin', Artisan::output());
        $this->assertSame('owner', $this->w->ownerA->fresh()->role);
    }

    public function test_revoke_does_not_mix_with_creating_or_with_a_bad_target_role(): void
    {
        [, $drop] = $this->twoSuperadmins();

        $this->assertSame(1, $this->make($drop->email, ['--revoke' => true, '--name' => 'X', '--force' => true]));
        $this->assertStringContainsString('cannot be combined', Artisan::output());
        $this->assertSame(1, $this->make($drop->email, ['--revoke' => true, '--to' => 'owner', '--force' => true]));
        $this->assertStringContainsString('--to must be admin or user', Artisan::output());
        $this->assertSame('superadmin', $drop->fresh()->role);
    }

    public function test_to_only_goes_with_revoke(): void
    {
        $this->assertSame(1, $this->make($this->w->stranger->email, ['--to' => 'user', '--force' => true]));

        $this->assertStringContainsString('--to only goes with --revoke', Artisan::output());
        $this->assertSame('user', $this->w->stranger->fresh()->role);
    }
}

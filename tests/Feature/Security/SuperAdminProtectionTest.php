<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\PmsTestCase;
use Tests\Support\World;

/** A superadmin outranks an admin, so an admin must not be able to change, lock out or take over a superadmin account. */
class SuperAdminProtectionTest extends PmsTestCase
{
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = World::user('superadmin', 'super@pms.test');
    }

    private function update(array $body, ?User $as = null)
    {
        return $this->as($as ?? $this->w->admin)->putJson("/api/v1/users/{$this->superadmin->id}", $body);
    }

    private function unchanged(): void
    {
        $fresh = $this->superadmin->fresh();
        $this->assertSame('superadmin', $fresh->role);
        $this->assertSame('active', $fresh->status);
        $this->assertSame('super@pms.test', $fresh->email);
        $this->assertTrue(Hash::check('password', $fresh->password), 'the password is unchanged');
    }

    public function test_an_admin_cannot_take_over_a_superadmin_by_changing_their_password(): void
    {
        $this->update(['password' => 'taken-over-123'])->assertForbidden();

        $this->unchanged();
    }

    public function test_an_admin_cannot_demote_deactivate_or_change_the_address_of_a_superadmin(): void
    {
        $this->update(['role' => 'manager'])->assertForbidden();
        $this->update(['status' => 'inactive'])->assertForbidden();
        $this->update(['email' => 'attacker@pms.test'])->assertForbidden();
        $this->update(['name' => 'Renamed'])->assertForbidden();

        $this->unchanged();
        $this->assertNotSame('Renamed', $this->superadmin->fresh()->name);
    }

    public function test_an_admin_cannot_delete_a_superadmin(): void
    {
        $this->as($this->w->admin)->deleteJson("/api/v1/users/{$this->superadmin->id}")->assertForbidden();

        $this->assertNotNull(User::query()->find($this->superadmin->id));
    }

    public function test_nobody_else_can_touch_a_superadmin_either(): void
    {
        foreach (['ownerA', 'managerA', 'financeA', 'tenantUser', 'stranger'] as $who) {
            $this->update(['password' => 'taken-over-123'], $this->w->{$who})->assertStatus($this->w->{$who}->isAdmin() ? 403 : 403);
        }

        $this->unchanged();
    }

    public function test_not_even_a_superadmin_can_change_a_superadmins_role_or_status_through_the_api(): void
    {
        $other = World::user('superadmin', 'other@pms.test');

        $this->as($this->superadmin)->putJson("/api/v1/users/{$other->id}", ['role' => 'admin'])->assertUnprocessable();
        $this->as($this->superadmin)->putJson("/api/v1/users/{$other->id}", ['status' => 'inactive'])->assertUnprocessable();
        $this->as($this->superadmin)->putJson("/api/v1/users/{$this->superadmin->id}", ['role' => 'admin'])->assertUnprocessable();    // nor their own

        $this->assertSame('superadmin', $other->fresh()->role);
        $this->assertSame('active', $other->fresh()->status);
        $this->assertSame('superadmin', $this->superadmin->fresh()->role);
    }

    public function test_a_superadmin_cannot_delete_a_superadmin_account_through_the_api(): void
    {
        $other = World::user('superadmin', 'other@pms.test');

        $this->as($this->superadmin)->deleteJson("/api/v1/users/{$other->id}")->assertUnprocessable();

        $this->assertNotNull(User::query()->find($other->id));
    }

    public function test_a_superadmin_can_still_edit_the_ordinary_details_of_a_superadmin_account(): void
    {
        $this->update(['phone' => '0800000001', 'title' => 'Platform owner'], $this->superadmin)->assertOk();

        $this->assertSame('Platform owner', $this->superadmin->fresh()->title);
        $this->assertSame('superadmin', $this->superadmin->fresh()->role);
    }

    public function test_an_admin_can_still_manage_everyone_who_is_not_a_superadmin(): void
    {
        $this->as($this->w->admin)->putJson("/api/v1/users/{$this->w->stranger->id}", ['title' => 'Member'])->assertOk();
        $this->as($this->w->admin)->putJson("/api/v1/users/{$this->w->financeA->id}", ['status' => 'inactive'])->assertOk();
    }

    public function test_the_error_says_what_to_do_instead(): void
    {
        $this->update(['password' => 'x-long-enough'])->assertForbidden()->assertJsonPath('message', 'Only a superadmin can change a superadmin account.');
        $this->as($this->superadmin)->putJson("/api/v1/users/{$this->superadmin->id}", ['role' => 'admin'])
            ->assertUnprocessable()->assertJsonPath('message', "A superadmin's role and status are managed with php artisan pms:make-superadmin.");
    }
}

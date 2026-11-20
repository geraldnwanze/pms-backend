<?php

namespace Tests\Feature\Security;

use App\Mail\PropertyInvitationMail;
use App\Models\PropertyMember;
use App\Models\User;
use App\Services\PropertyScope;
use Illuminate\Support\Facades\Mail;
use Tests\PmsTestCase;
use Tests\Support\World;

/** Invitations are how anyone gets access to a property: who may send them, who may accept them, and what each role unlocks. */
class MembershipTest extends PmsTestCase
{
    private function invite(User $as, string $email, string $role, string $property = 'prop_a')
    {
        return $this->as($as)->postJson("/api/v1/properties/{$property}/invitations", ['email' => $email, 'role' => $role]);
    }

    private function newUser(string $email, string $role = 'user'): User
    {
        return World::user($role, $email);
    }

    private function visibleProperties(User $user): array
    {
        $this->app->forgetInstance(PropertyScope::class);

        return collect($this->as($user)->getJson('/api/v1/properties')->assertOk()->json('data'))->pluck('id')->all();
    }

    // ---- Who may invite ------------------------------------------------------

    public function test_owners_managers_and_admins_can_invite_to_their_property(): void
    {
        foreach (['ownerA', 'managerA', 'admin'] as $i => $who) {
            $this->invite($this->w->{$who}, "new{$i}@pms.test", 'agent')->assertCreated()->assertJsonPath('data.status', 'invited');
        }
        Mail::assertQueued(PropertyInvitationMail::class, 3);
    }

    public function test_nobody_else_can_invite_or_list_the_team(): void
    {
        $agent = $this->newUser('agent@pms.test');
        World::member($this->w->pA, $agent, 'agent');

        foreach ([$this->w->financeA, $this->w->tenantUser, $this->w->stranger, $agent, $this->w->ownerB] as $user) {
            $this->invite($user, 'victim@pms.test', 'agent')->assertForbidden();
            $this->as($user)->getJson('/api/v1/properties/prop_a/members')->assertForbidden();
        }
        $this->assertSame(0, PropertyMember::query()->where('email', 'victim@pms.test')->count());
    }

    public function test_an_owner_cannot_invite_to_someone_elses_property(): void
    {
        $this->invite($this->w->ownerA, 'x@pms.test', 'manager', 'prop_b')->assertForbidden();
    }

    public function test_role_and_email_are_validated(): void
    {
        $this->invite($this->w->ownerA, 'x@pms.test', 'admin')->assertStatus(422);
        $this->invite($this->w->ownerA, 'x@pms.test', 'superuser')->assertStatus(422);
        $this->invite($this->w->ownerA, 'not-an-email', 'agent')->assertStatus(422);
        $this->assertSame(0, PropertyMember::query()->where('email', 'x@pms.test')->count());
    }

    public function test_duplicate_open_invitations_are_refused_but_a_different_role_is_fine(): void
    {
        $this->invite($this->w->ownerA, 'dup@pms.test', 'agent')->assertCreated();
        $this->invite($this->w->ownerA, 'DUP@pms.test', 'agent')->assertStatus(422);       // case-insensitive
        $this->invite($this->w->ownerA, 'dup@pms.test', 'finance')->assertCreated();
    }

    // ---- Privilege escalation -------------------------------------------------

    public function test_a_manager_cannot_hand_out_ownership(): void
    {
        $this->invite($this->w->managerA, 'friend@pms.test', 'owner')->assertForbidden();
        $this->assertSame(0, PropertyMember::query()->where('email', 'friend@pms.test')->count());
    }

    public function test_a_manager_cannot_remove_an_owner(): void
    {
        $second = $this->newUser('owner2@pms.test', 'owner');
        $row = World::member($this->w->pA, $second, 'owner');
        $first = PropertyMember::query()->where('userId', $this->w->ownerA->id)->where('propertyId', 'prop_a')->value('id');

        foreach ([$row->id, $first] as $id) {
            $this->as($this->w->managerA)->deleteJson("/api/v1/properties/prop_a/members/{$id}")->assertForbidden();
        }
        $this->assertSame(2, PropertyMember::query()->where('propertyId', 'prop_a')->where('role', 'owner')->where('status', 'active')->count());
    }

    public function test_an_owner_can_add_and_remove_owners(): void
    {
        $this->invite($this->w->ownerA, 'partner@pms.test', 'owner')->assertCreated();

        $second = $this->newUser('owner2@pms.test', 'owner');
        $row = World::member($this->w->pA, $second, 'owner');
        $this->as($this->w->ownerA)->deleteJson("/api/v1/properties/prop_a/members/{$row->id}")->assertOk();
    }

    public function test_the_last_owner_cannot_be_removed(): void
    {
        $own = PropertyMember::query()->where('userId', $this->w->ownerA->id)->where('propertyId', 'prop_a')->value('id');

        $this->as($this->w->ownerA)->deleteJson("/api/v1/properties/prop_a/members/{$own}")->assertStatus(422);
        $this->as($this->w->admin)->deleteJson("/api/v1/properties/prop_a/members/{$own}")->assertStatus(422);
        $this->assertSame('active', PropertyMember::query()->find($own)->status);
    }

    // ---- Accepting ------------------------------------------------------------

    public function test_the_invitee_accepts_and_gains_exactly_that_role_on_that_property(): void
    {
        $invitee = $this->newUser('mgr2@pms.test');
        $id = $this->invite($this->w->ownerA, 'mgr2@pms.test', 'manager')->assertCreated()->json('data.id');
        $this->assertSame([], $this->visibleProperties($invitee), 'a pending invite grants nothing');

        $this->as($invitee)->postJson("/api/v1/invitations/{$id}/accept")->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame(['prop_a'], $this->visibleProperties($invitee));
        $this->assertSame('manager', $invitee->fresh()->role);
        $this->assertSame('manager', $invitee->fresh()->roleOnProperty('prop_a'));
        $this->assertNull($invitee->fresh()->roleOnProperty('prop_b'));
    }

    public function test_an_invitation_for_one_address_cannot_be_accepted_by_another_account(): void
    {
        $id = $this->invite($this->w->ownerA, 'intended@pms.test', 'manager')->assertCreated()->json('data.id');
        $mallory = $this->newUser('mallory@pms.test');

        $this->as($mallory)->postJson("/api/v1/invitations/{$id}/accept")->assertNotFound();
        $this->as($mallory)->postJson("/api/v1/invitations/{$id}/decline")->assertNotFound();

        $this->assertSame('invited', PropertyMember::query()->find($id)->status);
        $this->assertSame([], $this->visibleProperties($mallory));
    }

    public function test_pending_invitations_are_only_listed_to_their_recipient(): void
    {
        $this->invite($this->w->ownerA, 'intended@pms.test', 'agent')->assertCreated();
        $intended = $this->newUser('intended@pms.test');
        $other = $this->newUser('other@pms.test');

        $this->assertCount(1, $this->as($intended)->getJson('/api/v1/invitations')->assertOk()->json('data'));
        $this->assertCount(0, $this->as($other)->getJson('/api/v1/invitations')->assertOk()->json('data'));
    }

    public function test_an_invitation_can_only_be_answered_once(): void
    {
        $invitee = $this->newUser('once@pms.test');
        $id = $this->invite($this->w->ownerA, 'once@pms.test', 'agent')->json('data.id');

        $this->as($invitee)->postJson("/api/v1/invitations/{$id}/accept")->assertOk();
        $this->postJson("/api/v1/invitations/{$id}/accept")->assertStatus(422);
        $this->postJson("/api/v1/invitations/{$id}/decline")->assertStatus(422);
    }

    public function test_declining_grants_nothing_and_cannot_be_reversed_by_accepting(): void
    {
        $invitee = $this->newUser('nope@pms.test');
        $id = $this->invite($this->w->ownerA, 'nope@pms.test', 'agent')->json('data.id');

        $this->as($invitee)->postJson("/api/v1/invitations/{$id}/decline")->assertOk()->assertJsonPath('data.status', 'declined');
        $this->postJson("/api/v1/invitations/{$id}/accept")->assertStatus(422);
        $this->assertSame([], $this->visibleProperties($invitee));
    }

    public function test_a_revoked_invitation_cannot_be_accepted(): void
    {
        $invitee = $this->newUser('late@pms.test');
        $id = $this->invite($this->w->ownerA, 'late@pms.test', 'agent')->json('data.id');
        $this->as($this->w->ownerA)->deleteJson("/api/v1/properties/prop_a/members/{$id}")->assertOk();

        $this->as($invitee)->postJson("/api/v1/invitations/{$id}/accept")->assertStatus(422);
        $this->assertSame([], $this->visibleProperties($invitee));
    }

    public function test_an_invite_can_be_sent_before_the_person_registers_and_they_can_accept_it_afterwards(): void
    {
        $id = $this->invite($this->w->ownerA, 'later@pms.test', 'finance')->assertCreated()->json('data.id');
        $this->assertNull(PropertyMember::query()->find($id)->userId);

        $later = $this->newUser('later@pms.test');
        $this->as($later)->postJson("/api/v1/invitations/{$id}/accept")->assertOk();

        $this->assertSame('finance', $later->fresh()->role);
        $this->assertSame($later->id, PropertyMember::query()->find($id)->userId);
    }

    public function test_accepting_a_tenant_invitation_creates_and_links_a_tenant_record(): void
    {
        $tenant = $this->newUser('newtenant@pms.test');
        $id = $this->invite($this->w->ownerA, 'newtenant@pms.test', 'tenant')->json('data.id');

        $this->as($tenant)->postJson("/api/v1/invitations/{$id}/accept")->assertOk();

        $tenant->refresh();
        $this->assertSame('tenant', $tenant->role);
        $this->assertNotEmpty($tenant->tenantId);
        $this->assertContains($tenant->tenantId, $tenant->tenantRecordIds());
        $this->assertDatabaseHas('tenants', ['id' => $tenant->tenantId, 'email' => 'newtenant@pms.test']);
    }

    public function test_holding_several_roles_resolves_to_the_most_senior(): void
    {
        $user = $this->newUser('multi@pms.test');
        foreach (['agent', 'manager'] as $role) {
            $id = $this->invite($this->w->ownerA, 'multi@pms.test', $role)->json('data.id');
            $this->as($user)->postJson("/api/v1/invitations/{$id}/accept")->assertOk();
        }

        $this->assertSame('manager', $user->fresh()->role);
    }

    // ---- Revoking -------------------------------------------------------------

    public function test_revoking_removes_access_at_once_and_resets_the_role(): void
    {
        $this->assertSame(['prop_a'], $this->visibleProperties($this->w->managerA));
        $row = PropertyMember::query()->where('userId', $this->w->managerA->id)->value('id');

        $this->as($this->w->ownerA)->deleteJson("/api/v1/properties/prop_a/members/{$row}")->assertOk();

        $this->assertSame([], $this->visibleProperties($this->w->managerA));
        $this->assertSame('user', $this->w->managerA->fresh()->role);
    }

    public function test_a_member_id_from_another_property_cannot_be_revoked_through_this_one(): void
    {
        $theirs = PropertyMember::query()->where('propertyId', 'prop_b')->value('id');

        $this->as($this->w->ownerA)->deleteJson("/api/v1/properties/prop_a/members/{$theirs}")->assertNotFound();
        $this->as($this->w->ownerA)->deleteJson("/api/v1/properties/prop_b/members/{$theirs}")->assertForbidden();
        $this->assertSame('active', PropertyMember::query()->find($theirs)->status);
    }

    public function test_the_team_list_shows_the_properties_members(): void
    {
        $emails = collect($this->as($this->w->ownerA)->getJson('/api/v1/properties/prop_a/members')->assertOk()->json('data'))->pluck('email');

        $this->assertContains($this->w->managerA->email, $emails);
        $this->assertNotContains($this->w->ownerB->email, $emails);
    }

    // ---- Payout bank details ---------------------------------------------------

    private function bank(User $as, string $member, array $body)
    {
        return $this->as($as)->putJson("/api/v1/memberships/{$member}/bank", $body);
    }

    public function test_a_member_sets_their_own_payout_details(): void
    {
        $row = PropertyMember::query()->where('userId', $this->w->financeA->id)->value('id');

        $this->bank($this->w->financeA, $row, ['bankName' => 'GTBank', 'bankAccountName' => 'Fin A', 'bankAccountNumber' => '0123456789', 'bankCode' => '058'])
            ->assertOk()->assertJsonPath('data.hasBankDetails', true);
    }

    public function test_nobody_can_change_someone_elses_payout_details(): void
    {
        $row = PropertyMember::query()->where('userId', $this->w->financeA->id)->value('id');

        foreach ([$this->w->ownerA, $this->w->managerA, $this->w->admin, $this->w->stranger] as $user) {
            $this->bank($user, $row, ['bankName' => 'Evil Bank', 'bankAccountNumber' => '9999999999'])->assertNotFound();
        }
        $this->assertNull(PropertyMember::query()->find($row)->bankAccountNumber);
    }

    public function test_payout_details_are_validated_and_refused_for_tenants_and_pending_invites(): void
    {
        $row = PropertyMember::query()->where('userId', $this->w->financeA->id)->value('id');
        $this->bank($this->w->financeA, $row, ['bankAccountNumber' => '12AB'])->assertStatus(422);
        $this->bank($this->w->financeA, $row, ['bankAccountNumber' => str_repeat('1', 21)])->assertStatus(422);

        $tenantRow = PropertyMember::query()->where('userId', $this->w->tenantUser->id)->value('id');
        $this->bank($this->w->tenantUser, $tenantRow, ['bankAccountNumber' => '0123456789'])->assertStatus(422);

        $pending = $this->newUser('pending@pms.test');
        $id = $this->invite($this->w->ownerA, 'pending@pms.test', 'maintenance')->json('data.id');
        $this->bank($pending, $id, ['bankAccountNumber' => '0123456789'])->assertStatus(422);
    }
}

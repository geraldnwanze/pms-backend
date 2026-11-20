<?php

namespace Tests\Feature\Operations;

use App\Models\Lead;
use App\Models\Listing;
use App\Models\PropertyMember;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;
use Tests\Support\World;

/** The leasing side: listings go live, leads move through the funnel, and each person sees the right ones. */
class LeadAndListingTest extends PmsTestCase
{
    private User $agentA;

    private User $agentA2;

    private User $agentB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');

        $this->agentA = World::user('agent', 'agent.a@pms.test');
        $this->agentA2 = World::user('agent', 'agent.a2@pms.test');
        $this->agentB = World::user('agent', 'agent.b@pms.test');
        World::member($this->w->pA, $this->agentA, 'agent');
        World::member($this->w->pA, $this->agentA2, 'agent');
        World::member($this->w->pB, $this->agentB, 'agent');
    }

    private function lead(string $id, ?string $property, string $status = 'new', ?string $agent = null, array $over = []): Lead
    {
        return Lead::query()->create($over + [
            'id' => $id, 'name' => "Lead {$id}", 'email' => "{$id}@x.test", 'phone' => '0800', 'propertyId' => $property,
            'status' => $status, 'agentId' => $agent, 'createdAt' => '2026-11-01T08:00:00+00:00',
        ]);
    }

    private function ids(User $as, string $query = ''): array
    {
        return collect($this->as($as)->getJson('/api/v1/leads?per_page=100'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    // ======================= Leads ==========================================

    public function test_a_new_lead_starts_new_with_the_time_it_came_in(): void
    {
        $res = $this->as($this->agentA)->postJson('/api/v1/leads', ['name' => 'Lee', 'email' => 'lee@x.test', 'phone' => '0800', 'propertyId' => 'prop_a', 'agentId' => $this->agentA->id])
            ->assertCreated()->assertJsonPath('data.status', 'new');

        $this->assertStringStartsWith('2026-11-15', $res->json('data.createdAt'));
    }

    public function test_a_lead_needs_a_name_valid_email_and_phone_and_a_known_status(): void
    {
        $ok = ['name' => 'Lee', 'email' => 'lee@x.test', 'phone' => '0800', 'propertyId' => 'prop_a'];
        $this->as($this->agentA);

        foreach (['name', 'email', 'phone'] as $field) {
            $payload = $ok;
            unset($payload[$field]);
            $this->postJson('/api/v1/leads', $payload)->assertStatus(422);
        }
        $this->postJson('/api/v1/leads', ['email' => 'nope'] + $ok)->assertStatus(422);
        $this->postJson('/api/v1/leads', ['status' => 'maybe'] + $ok)->assertStatus(422);
        $this->postJson('/api/v1/leads', ['viewingDate' => 'someday'] + $ok)->assertStatus(422);
    }

    public function test_a_lead_moves_through_the_funnel_and_can_be_given_a_viewing(): void
    {
        $lead = $this->lead('l1', 'prop_a', 'new', $this->agentA->id);
        $this->as($this->agentA);

        foreach (['contacted', 'viewing-scheduled', 'negotiating', 'converted'] as $status) {
            $this->patchJson("/api/v1/leads/{$lead->id}", ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
        }
        $this->patchJson("/api/v1/leads/{$lead->id}", ['status' => 'lost', 'viewingDate' => '2026-11-20', 'notes' => 'Went elsewhere'])
            ->assertOk()->assertJsonPath('data.viewingDate', '2026-11-20')->assertJsonPath('data.notes', 'Went elsewhere');
    }

    public function test_an_agent_sees_only_their_own_leads(): void
    {
        $mine = $this->lead('l_mine', 'prop_a', 'new', $this->agentA->id);
        $colleagues = $this->lead('l_colleague', 'prop_a', 'new', $this->agentA2->id);
        $unassigned = $this->lead('l_open', 'prop_a');

        $this->assertSame([$mine->id], $this->ids($this->agentA));
        foreach ([$colleagues, $unassigned] as $hidden) {
            $this->as($this->agentA);
            $this->assertContains($this->getJson("/api/v1/leads/{$hidden->id}")->status(), [403, 404]);
            $this->assertContains($this->patchJson("/api/v1/leads/{$hidden->id}", ['status' => 'lost'])->status(), [403, 404]);
        }
        $this->assertSame('new', $colleagues->fresh()->status);
    }

    public function test_an_agent_who_has_left_a_property_no_longer_sees_its_leads_even_if_they_were_assigned(): void
    {
        $kept = $this->lead('l_kept', 'prop_a', 'new', $this->agentA->id);
        $this->assertSame([$kept->id], $this->ids($this->agentA));

        PropertyMember::query()->where('userId', $this->agentA->id)->update(['status' => 'revoked']);

        $this->assertSame([], $this->ids($this->agentA));
    }

    public function test_a_manager_sees_the_leads_of_their_properties_and_not_other_portfolios(): void
    {
        $a = $this->lead('l_a', 'prop_a', 'new', $this->agentA->id);
        $b = $this->lead('l_b', 'prop_b', 'new', $this->agentB->id);

        $ids = $this->ids($this->w->managerA);

        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids, 'a manager of property A must not read property B’s prospects');
        $this->assertContains($this->as($this->w->managerA)->getJson("/api/v1/leads/{$b->id}")->status(), [403, 404]);
    }

    public function test_owners_see_their_propertys_leads_and_admins_see_everything(): void
    {
        $a = $this->lead('l_a', 'prop_a');
        $b = $this->lead('l_b', 'prop_b');

        $this->assertSame([$a->id], $this->ids($this->w->ownerA));
        $this->assertSame([$b->id], $this->ids($this->w->ownerB));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids($this->w->admin));
    }

    public function test_a_lead_with_no_property_is_for_admins_and_the_agent_it_is_assigned_to(): void
    {
        $general = $this->lead('l_general', null, 'new', $this->agentA->id);

        $this->assertContains($general->id, $this->ids($this->agentA));
        $this->assertContains($general->id, $this->ids($this->w->admin));
        $this->assertNotContains($general->id, $this->ids($this->w->managerA));
        $this->assertNotContains($general->id, $this->ids($this->w->ownerB));
        $this->assertNotContains($general->id, $this->ids($this->agentB));
    }

    public function test_only_leasing_staff_can_see_leads(): void
    {
        $this->lead('l_a', 'prop_a');

        foreach (['tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who})->getJson('/api/v1/leads')->assertForbidden();
        }
    }

    public function test_who_may_create_and_change_leads(): void
    {
        $lead = $this->lead('l_a', 'prop_a', 'new', $this->agentA->id);
        $payload = ['name' => 'Lee', 'email' => 'lee@x.test', 'phone' => '0800', 'propertyId' => 'prop_a'];

        $this->as($this->w->managerA)->postJson('/api/v1/leads', $payload)->assertCreated();
        $this->as($this->agentA)->postJson('/api/v1/leads', $payload)->assertCreated();
        foreach (['ownerA', 'financeA', 'tenantUser'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/leads', $payload)->assertForbidden();
        }
        foreach (['ownerA', 'financeA'] as $who) {
            $this->as($this->w->{$who})->patchJson("/api/v1/leads/{$lead->id}", ['status' => 'lost'])->assertForbidden();
        }
    }

    public function test_a_lead_can_be_handed_to_another_agent_only_if_they_are_on_the_property(): void
    {
        $lead = $this->lead('l_a', 'prop_a', 'new', $this->agentA->id);
        $this->as($this->w->managerA);

        $this->patchJson("/api/v1/leads/{$lead->id}", ['agentId' => $this->agentB->id])->assertStatus(422);
        $this->patchJson("/api/v1/leads/{$lead->id}", ['agentId' => $this->agentA2->id])->assertOk();
        $this->assertSame($this->agentA2->id, $lead->fresh()->agentId);
    }

    public function test_leads_can_be_filtered_searched_and_sorted(): void
    {
        $this->lead('l1', 'prop_a', 'new', $this->agentA->id, ['name' => 'Zainab Bello', 'email' => 'zee@x.test', 'phone' => '0801111', 'notes' => 'wants a balcony', 'viewingDate' => '2026-12-01']);
        $this->lead('l2', 'prop_a', 'contacted', $this->agentA2->id, ['name' => 'Adaeze Obi', 'email' => 'ada@x.test', 'phone' => '0802222', 'viewingDate' => '2026-11-20']);

        $this->assertSame(['l2'], $this->ids($this->w->managerA, '&status=contacted'));
        $this->assertSame(['l1'], $this->ids($this->w->managerA, '&agentId='.$this->agentA->id));
        foreach (['zainab', 'ZEE@x', '0801111', 'balcony'] as $term) {
            $this->assertSame(['l1'], $this->ids($this->w->managerA, "&search={$term}"), $term);
        }
        $this->assertSame(['l2', 'l1'], $this->ids($this->w->managerA, '&sort=viewingDate'));
    }

    // ======================= Listings =========================================

    private function listing(array $over = []): array
    {
        return $over + ['propertyId' => 'prop_a', 'unitId' => 'unit_a', 'title' => '2-bed flat', 'price' => 1_800_000];
    }

    public function test_a_listing_goes_live_straight_away_stamped_with_the_time(): void
    {
        $res = $this->as($this->agentA)->postJson('/api/v1/listings', $this->listing())->assertCreated()->assertJsonPath('data.status', 'published');

        $this->assertStringStartsWith('2026-11-15', $res->json('data.publishedAt'));
    }

    public function test_a_listing_can_be_kept_as_a_draft_and_moved_through_its_states(): void
    {
        $id = $this->as($this->agentA)->postJson('/api/v1/listings', $this->listing(['status' => 'draft']))->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        foreach (['published', 'let', 'archived'] as $status) {
            $this->patchJson("/api/v1/listings/{$id}", ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
        }
        $this->patchJson("/api/v1/listings/{$id}", ['status' => 'sold'])->assertStatus(422);
    }

    public function test_a_listing_needs_a_title_a_unit_and_a_non_negative_price(): void
    {
        $this->as($this->agentA);

        foreach (['title', 'unitId', 'price', 'propertyId'] as $field) {
            $payload = $this->listing();
            unset($payload[$field]);
            $this->postJson('/api/v1/listings', $payload)->assertStatus(422);
        }
        $this->postJson('/api/v1/listings', $this->listing(['price' => -1]))->assertStatus(422);
        $this->postJson('/api/v1/listings', $this->listing(['price' => 'cheap']))->assertStatus(422);
    }

    public function test_listings_are_scoped_to_the_property_and_the_listing_agent_must_be_on_it(): void
    {
        $mine = Listing::query()->create($this->listing(['id' => 'lst_a', 'status' => 'published']));
        $theirs = Listing::query()->create(['id' => 'lst_b', 'propertyId' => 'prop_b', 'unitId' => 'unit_b', 'title' => 'B flat', 'price' => 1, 'status' => 'published']);

        $ids = fn (User $u) => collect($this->as($u)->getJson('/api/v1/listings?per_page=100')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids($this->agentA));
        $this->assertSame([$mine->id], $ids($this->w->managerA));
        $this->assertSame([$theirs->id], $ids($this->w->ownerB));

        $this->as($this->w->managerA)->postJson('/api/v1/listings', $this->listing(['agentId' => $this->agentB->id]))->assertStatus(422);
        $this->postJson('/api/v1/listings', $this->listing(['agentId' => $this->agentA->id]))->assertCreated();
    }

    public function test_tenants_can_see_what_is_on_the_market_in_their_own_building_only(): void
    {
        $mine = Listing::query()->create($this->listing(['id' => 'lst_a']));
        Listing::query()->create(['id' => 'lst_b', 'propertyId' => 'prop_b', 'unitId' => 'unit_b', 'title' => 'B', 'price' => 1, 'status' => 'published']);

        $ids = collect($this->as($this->w->tenantUser)->getJson('/api/v1/listings')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
        $this->as($this->w->tenantUser)->postJson('/api/v1/listings', $this->listing())->assertForbidden();
    }

    public function test_listings_can_be_filtered_by_status_and_agent(): void
    {
        Listing::query()->create($this->listing(['id' => 'lst_1', 'status' => 'published', 'agentId' => $this->agentA->id]));
        Listing::query()->create($this->listing(['id' => 'lst_2', 'status' => 'draft', 'agentId' => $this->agentA2->id]));

        $ids = fn (string $q) => collect($this->as($this->w->managerA)->getJson("/api/v1/listings?per_page=100{$q}")->json('data'))->pluck('id')->all();

        $this->assertSame(['lst_2'], $ids('&status=draft'));
        $this->assertSame(['lst_1'], $ids('&agentId='.$this->agentA->id));
        $this->assertSame(['lst_1'], $ids('&search=2-bed&status=published'));
    }
}

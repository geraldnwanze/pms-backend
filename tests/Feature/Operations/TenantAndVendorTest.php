<?php

namespace Tests\Feature\Operations;

use App\Models\Lease;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;

/** The people and companies a property deals with: who can see them, and the numbers kept about them. */
class TenantAndVendorTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
    }

    // ======================= Tenants ==========================================

    private function newTenant(array $over = []): array
    {
        return $over + ['name' => 'Chidi Eze', 'email' => 'chidi@tenant.test', 'phone' => '0803', 'identityType' => 'nin', 'identityNumber' => '12345678901', 'ownerId' => $this->w->ownerA->id];
    }

    private function tenantIds(User $as, string $query = ''): array
    {
        return collect($this->as($as)->getJson('/api/v1/tenants?per_page=100'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_a_tenant_record_is_stamped_with_the_day_it_was_created(): void
    {
        $res = $this->as($this->w->managerA)->postJson('/api/v1/tenants', $this->newTenant())->assertCreated();

        $this->assertStringStartsWith('2026-11-15', (string) $res->json('data.createdAt'));
    }

    public function test_a_tenant_needs_contact_details_and_an_identity_and_an_owner(): void
    {
        $this->as($this->w->managerA);

        foreach (['name', 'email', 'phone', 'identityType', 'identityNumber', 'ownerId'] as $field) {
            $payload = $this->newTenant();
            unset($payload[$field]);
            $this->postJson('/api/v1/tenants', $payload)->assertStatus(422);
        }
        $this->postJson('/api/v1/tenants', $this->newTenant(['email' => 'not-an-email']))->assertStatus(422);
    }

    public function test_a_tenant_sees_only_themselves(): void
    {
        $this->assertSame(['ten_ada'], $this->tenantIds($this->w->tenantUser));
    }

    public function test_staff_see_the_tenants_on_leases_at_their_properties_and_nobody_elses(): void
    {
        foreach (['managerA', 'ownerA', 'financeA'] as $who) {
            $ids = $this->tenantIds($this->w->{$who});
            $this->assertContains('ten_ada', $ids, $who);
            $this->assertNotContains('ten_bee', $ids, $who);
        }
        $this->assertSame(['ten_bee'], $this->tenantIds($this->w->ownerB));
        $this->assertEqualsCanonicalizing(['ten_ada', 'ten_bee'], $this->tenantIds($this->w->admin));
    }

    public function test_a_tenant_registered_before_any_lease_is_still_visible_to_the_people_who_run_that_portfolio(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/tenants', $this->newTenant())->assertCreated()->json('data.id');

        $this->assertContains($id, $this->tenantIds($this->w->managerA), 'the person who just registered them must be able to find them');
        $this->assertContains($id, $this->tenantIds($this->w->ownerA));
        $this->assertNotContains($id, $this->tenantIds($this->w->ownerB), 'and nobody in another portfolio');
        $this->assertContains($this->as($this->w->ownerB)->getJson("/api/v1/tenants/{$id}")->status(), [403, 404]);
    }

    public function test_a_former_tenant_whose_lease_ended_stays_on_record_for_that_portfolio(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/tenants', $this->newTenant())->json('data.id');
        Lease::query()->create(['id' => 'l_gone', 'code' => 'LG', 'unitId' => 'unit_a', 'propertyId' => 'prop_a', 'tenantId' => $id, 'startDate' => '2024-01-01', 'endDate' => '2025-01-01', 'rent' => 1, 'billingCycle' => 'annual', 'status' => 'terminated', 'signed' => true]);

        $this->assertContains($id, $this->tenantIds($this->w->managerA));
    }

    public function test_who_may_register_and_change_tenants(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/tenants', $this->newTenant())->assertCreated();
        foreach (['ownerA', 'financeA', 'tenantUser'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/tenants', $this->newTenant(['email' => "{$who}@t.test"]))->assertForbidden();
        }

        $this->as($this->w->managerA)->patchJson('/api/v1/tenants/ten_ada', ['phone' => '0900'])->assertOk()->assertJsonPath('data.phone', '0900');
        $this->as($this->w->tenantUser)->patchJson('/api/v1/tenants/ten_ada', ['phone' => '0911'])->assertForbidden();
        $this->assertContains($this->as($this->w->ownerB)->patchJson('/api/v1/tenants/ten_ada', ['phone' => '0911'])->status(), [403, 404]);
        $this->assertSame('0900', Tenant::query()->find('ten_ada')->phone);
    }

    public function test_only_an_admin_can_delete_a_tenant(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/tenants', $this->newTenant())->json('data.id');

        $this->deleteJson("/api/v1/tenants/{$id}")->assertForbidden();
        $this->as($this->w->admin)->deleteJson("/api/v1/tenants/{$id}")->assertOk();
    }

    public function test_tenants_can_be_found_by_name_email_phone_or_id_number_and_filtered_by_owner(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/tenants', $this->newTenant(['name' => 'Chidi Eze', 'email' => 'chidi@tenant.test', 'phone' => '0803777', 'identityNumber' => 'NIN-998877']))->json('data.id');

        foreach (['chidi', 'CHIDI@TENANT', '0803777', 'NIN-998877'] as $term) {
            $this->assertContains($id, $this->tenantIds($this->w->managerA, "&search={$term}"), $term);
        }
        $this->assertNotContains('ten_ada', $this->tenantIds($this->w->managerA, '&search=chidi'));
        $this->assertContains($id, $this->tenantIds($this->w->managerA, '&ownerId='.$this->w->ownerA->id));
        $this->assertSame([], $this->tenantIds($this->w->managerA, '&ownerId=nobody'));
    }

    // ======================= Vendors ==========================================

    private function vendor(string $id = 'vnd_a', ?string $owner = null): Vendor
    {
        return Vendor::query()->create(['id' => $id, 'name' => "Vendor {$id}", 'service' => 'plumbing', 'phone' => '0801', 'email' => "{$id}@v.test", 'ownerId' => $owner ?? $this->w->ownerA->id, 'totalPaid' => 0, 'outstanding' => 0]);
    }

    private function expenseFor(Vendor $v, int $amount): string
    {
        return $this->as($this->w->managerA)->postJson('/api/v1/expenses', ['propertyId' => 'prop_a', 'category' => 'repairs', 'vendorId' => $v->id, 'description' => 'Work', 'amount' => $amount, 'date' => '2026-11-10'])
            ->assertCreated()->json('data.id');
    }

    private function moveExpense(User $by, string $expense, string $status): void
    {
        $this->as($by)->postJson("/api/v1/expenses/{$expense}/status", ['status' => $status])->assertOk();
    }

    public function test_a_vendor_needs_contact_details_and_an_owner_and_starts_at_zero(): void
    {
        $ok = ['name' => 'Pipes Ltd', 'service' => 'plumbing', 'phone' => '0801', 'email' => 'p@v.test', 'ownerId' => $this->w->ownerA->id];
        $this->as($this->w->managerA);

        foreach (['name', 'service', 'phone', 'email', 'ownerId'] as $field) {
            $payload = $ok;
            unset($payload[$field]);
            $this->postJson('/api/v1/vendors', $payload)->assertStatus(422);
        }
        $this->postJson('/api/v1/vendors', ['email' => 'nope'] + $ok)->assertStatus(422);
        $this->postJson('/api/v1/vendors', $ok)->assertCreated()->assertJsonPath('data.totalPaid', 0)->assertJsonPath('data.outstanding', 0);
    }

    public function test_the_amounts_owed_to_a_vendor_follow_their_expenses_through_approval_and_payment(): void
    {
        $v = $this->vendor();
        $pending = $this->expenseFor($v, 30_000);
        $this->assertSame([0, 0], [(int) $v->fresh()->totalPaid, (int) $v->fresh()->outstanding], 'a pending expense is not owed yet');

        $this->moveExpense($this->w->managerA, $pending, 'approved');
        $this->assertSame([0, 30_000], [(int) $v->fresh()->totalPaid, (int) $v->fresh()->outstanding], 'approved but unpaid is outstanding');

        $this->moveExpense($this->w->financeA, $pending, 'paid');
        $this->assertSame([30_000, 0], [(int) $v->fresh()->totalPaid, (int) $v->fresh()->outstanding], 'paid moves from outstanding to paid');

        $second = $this->expenseFor($v, 20_000);
        $this->moveExpense($this->w->managerA, $second, 'approved');
        $this->assertSame([30_000, 20_000], [(int) $v->fresh()->totalPaid, (int) $v->fresh()->outstanding]);
    }

    public function test_a_rejected_expense_never_counts_and_a_resubmitted_one_is_not_owed(): void
    {
        $v = $this->vendor();
        $id = $this->expenseFor($v, 10_000);

        $this->moveExpense($this->w->managerA, $id, 'approved');
        $this->moveExpense($this->w->managerA, $id, 'rejected');
        $this->assertSame([0, 0], [(int) $v->fresh()->totalPaid, (int) $v->fresh()->outstanding], 'rejecting an approved expense takes it back off the books');

        $this->moveExpense($this->w->managerA, $id, 'pending');
        $this->assertSame([0, 0], [(int) $v->fresh()->totalPaid, (int) $v->fresh()->outstanding]);
    }

    public function test_each_vendors_totals_are_their_own(): void
    {
        $a = $this->vendor('vnd_a');
        $b = $this->vendor('vnd_b');
        $this->moveExpense($this->w->managerA, $this->expenseFor($a, 11_000), 'approved');
        $this->moveExpense($this->w->managerA, $this->expenseFor($b, 22_000), 'approved');

        $this->assertSame(11_000, (int) $a->fresh()->outstanding);
        $this->assertSame(22_000, (int) $b->fresh()->outstanding);
    }

    public function test_removing_a_paid_expense_takes_it_out_of_the_vendors_total(): void
    {
        $v = $this->vendor();
        $id = $this->expenseFor($v, 40_000);
        $this->moveExpense($this->w->managerA, $id, 'approved');
        $this->moveExpense($this->w->financeA, $id, 'paid');
        $this->assertSame(40_000, (int) $v->fresh()->totalPaid);

        $this->as($this->w->admin)->deleteJson("/api/v1/expenses/{$id}")->assertOk();

        $this->assertSame(0, (int) $v->fresh()->totalPaid);
    }

    public function test_a_vendors_figures_cannot_be_typed_in(): void
    {
        $v = $this->vendor();

        $this->as($this->w->managerA)->patchJson("/api/v1/vendors/{$v->id}", ['totalPaid' => 9_999_999, 'outstanding' => 5, 'name' => 'Renamed'])->assertOk();

        $fresh = $v->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame([0, 0], [(int) $fresh->totalPaid, (int) $fresh->outstanding]);

        $created = $this->as($this->w->managerA)->postJson('/api/v1/vendors', ['name' => 'Sneaky', 'service' => 's', 'phone' => '1', 'email' => 's@v.test', 'ownerId' => $this->w->ownerA->id, 'totalPaid' => 1_000_000])->json('data');
        $this->assertSame(0, $created['totalPaid']);
    }

    public function test_who_may_see_and_manage_vendors(): void
    {
        $v = $this->vendor();
        $new = ['name' => 'Pipes Ltd', 'service' => 'plumbing', 'phone' => '0801', 'email' => 'p@v.test', 'ownerId' => $this->w->ownerA->id];

        foreach (['managerA', 'financeA', 'ownerA'] as $who) {
            $this->as($this->w->{$who})->getJson('/api/v1/vendors')->assertOk();
        }
        foreach (['tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who})->getJson('/api/v1/vendors')->assertForbidden();
        }
        foreach (['managerA', 'financeA'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/vendors', $new)->assertCreated();
        }
        $this->as($this->w->ownerA)->postJson('/api/v1/vendors', $new)->assertForbidden();
        $this->assertContains($this->as($this->w->ownerB)->patchJson("/api/v1/vendors/{$v->id}", ['name' => 'x'])->status(), [403, 404]);
    }

    public function test_vendors_can_be_searched_and_sorted_by_what_they_are_owed(): void
    {
        $this->vendor('vnd_a')->forceFill(['name' => 'Alpha Pipes', 'service' => 'plumbing', 'totalPaid' => 50_000])->save();
        $this->vendor('vnd_b')->forceFill(['name' => 'Beta Sparks', 'service' => 'electrical', 'totalPaid' => 90_000])->save();

        $ids = fn (string $q) => collect($this->as($this->w->managerA)->getJson("/api/v1/vendors?per_page=100{$q}")->json('data'))->pluck('id')->all();

        $this->assertSame(['vnd_a'], $ids('&search=pipes'));
        $this->assertSame(['vnd_b'], $ids('&search=electrical'));
        $this->assertSame(['vnd_b', 'vnd_a'], $ids('&sort=-totalPaid'));
    }
}

<?php

namespace Tests\Feature\Documents;

use App\Models\DocumentRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;

/** The document library: who files what, under which property, who sees it, who may change or remove it. */
class DocumentTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
    }

    private function doc(array $over = []): array
    {
        return $over + ['name' => 'Title deed.pdf', 'category' => 'deed', 'fileType' => 'pdf', 'sizeKb' => 120, 'propertyId' => 'prop_a'];
    }

    private function make(User $by, array $over = []): string
    {
        return $this->as($by)->postJson('/api/v1/documents', $this->doc($over))->assertCreated()->json('data.id');
    }

    private function visibleTo(User $who): array
    {
        return collect($this->as($who)->getJson('/api/v1/documents?per_page=100')->assertOk()->json('data'))->pluck('id')->all();
    }

    // ---- Filing -------------------------------------------------------------

    public function test_the_server_states_who_uploaded_and_when(): void
    {
        $res = $this->as($this->w->managerA)
            ->postJson('/api/v1/documents', $this->doc(['uploadedById' => $this->w->ownerB->id, 'uploadedAt' => '1999-01-01T00:00:00+00:00']))
            ->assertCreated();

        $res->assertJsonPath('data.uploadedById', $this->w->managerA->id);
        $this->assertStringStartsWith('2026-11-15', $res->json('data.uploadedAt'));
    }

    public function test_a_document_needs_a_name_category_and_file_type_from_the_allowed_lists(): void
    {
        $this->as($this->w->managerA);

        foreach (['name', 'category', 'fileType'] as $field) {
            $payload = $this->doc();
            unset($payload[$field]);
            $this->postJson('/api/v1/documents', $payload)->assertStatus(422);
        }
        $this->postJson('/api/v1/documents', $this->doc(['category' => 'secrets']))->assertStatus(422);
        $this->postJson('/api/v1/documents', $this->doc(['fileType' => 'exe']))->assertStatus(422);
        $this->postJson('/api/v1/documents', $this->doc(['sizeKb' => -1]))->assertStatus(422);
    }

    public function test_a_document_cannot_be_filed_under_another_property(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/documents', $this->doc(['propertyId' => 'prop_b']))->assertForbidden();
        $this->as($this->w->ownerB)->postJson('/api/v1/documents', $this->doc(['propertyId' => 'prop_a']))->assertForbidden();
        $this->assertSame(0, DocumentRecord::query()->count());
    }

    public function test_a_unit_on_the_document_must_belong_to_its_property_and_stands_in_for_it(): void
    {
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/documents', $this->doc(['unitId' => 'unit_b']))->assertStatus(422);

        $id = $this->postJson('/api/v1/documents', ['name' => 'Inspection.pdf', 'category' => 'inspection', 'fileType' => 'pdf', 'unitId' => 'unit_a'])
            ->assertCreated()->json('data.id');
        $this->assertSame('prop_a', DocumentRecord::query()->find($id)->propertyId, 'filed under the unit’s property, not left open');
    }

    public function test_a_document_about_a_tenant_must_sit_under_a_property(): void
    {
        $this->as($this->w->managerA);
        $doc = ['name' => 'Passport.pdf', 'category' => 'tenant-id', 'fileType' => 'pdf', 'tenantId' => 'ten_ada'];

        $this->postJson('/api/v1/documents', $doc)->assertStatus(422);
        $this->postJson('/api/v1/documents', $doc + ['propertyId' => 'prop_a'])->assertCreated();
    }

    public function test_a_tenant_can_file_their_own_document_but_not_for_someone_else_or_elsewhere(): void
    {
        $doc = ['name' => 'My ID.pdf', 'category' => 'tenant-id', 'fileType' => 'pdf', 'propertyId' => 'prop_a'];
        $this->as($this->w->tenantUser);

        $this->postJson('/api/v1/documents', $doc + ['tenantId' => 'ten_ada'])->assertCreated();
        $this->postJson('/api/v1/documents', $doc + ['tenantId' => 'ten_bee'])->assertStatus(422);
        $this->postJson('/api/v1/documents', ['propertyId' => 'prop_b'] + $doc + ['tenantId' => 'ten_ada'])->assertForbidden();
        $this->postJson('/api/v1/documents', array_diff_key($doc, ['propertyId' => 1]) + ['tenantId' => 'ten_ada'])->assertStatus(422);
    }

    public function test_it_needs_a_login(): void
    {
        $this->postJson('/api/v1/documents', $this->doc())->assertUnauthorized();
        $this->getJson('/api/v1/documents')->assertUnauthorized();
    }

    // ---- Who sees what -----------------------------------------------------

    public function test_staff_see_their_properties_documents_and_nobody_elses(): void
    {
        $mine = $this->make($this->w->managerA);
        $theirs = $this->make($this->w->admin, ['propertyId' => 'prop_b']);

        foreach (['managerA', 'ownerA', 'financeA'] as $who) {
            $ids = $this->visibleTo($this->w->{$who});
            $this->assertContains($mine, $ids, $who);
            $this->assertNotContains($theirs, $ids, $who);
        }
        $this->assertSame([$theirs], $this->visibleTo($this->w->ownerB));
        $this->assertEqualsCanonicalizing([$mine, $theirs], $this->visibleTo($this->w->admin));
        $this->as($this->w->stranger)->getJson('/api/v1/documents')->assertForbidden();   // on no property: no library
    }

    public function test_opening_another_propertys_document_by_id_is_refused(): void
    {
        $theirs = $this->make($this->w->admin, ['propertyId' => 'prop_b']);

        foreach (['managerA', 'tenantUser'] as $who) {
            $this->assertContains($this->as($this->w->{$who})->getJson("/api/v1/documents/{$theirs}")->status(), [403, 404], $who);
        }
    }

    public function test_a_tenant_sees_only_documents_filed_against_themselves(): void
    {
        Tenant::query()->create(['id' => 'ten_other', 'name' => 'Other', 'email' => 'o@t.test', 'phone' => '0', 'createdAt' => '2026-01-01']);
        $mine = $this->make($this->w->managerA, ['tenantId' => 'ten_ada', 'category' => 'tenant-id']);
        $other = $this->make($this->w->admin, ['tenantId' => 'ten_other', 'category' => 'tenant-id']);
        $general = $this->make($this->w->managerA);                       // property-level, not about this tenant

        $this->assertSame([$mine], $this->visibleTo($this->w->tenantUser));
        foreach ([$other, $general] as $hidden) {
            $this->assertContains($this->as($this->w->tenantUser)->getJson("/api/v1/documents/{$hidden}")->status(), [403, 404]);
        }
    }

    public function test_a_portfolio_wide_document_is_seen_by_the_uploader_and_their_colleagues_only(): void
    {
        $wide = $this->make($this->w->managerA, ['propertyId' => null, 'category' => 'tax', 'name' => 'Tax return.pdf']);

        foreach (['managerA', 'ownerA', 'financeA', 'admin'] as $who) {
            $this->assertContains($wide, $this->visibleTo($this->w->{$who}), $who);
        }
        $this->assertNotContains($wide, $this->visibleTo($this->w->ownerB), 'another owner’s staff share no property with the uploader');
        foreach (['ownerB', 'stranger'] as $who) {
            $this->assertContains($this->as($this->w->{$who})->getJson("/api/v1/documents/{$wide}")->status(), [403, 404], $who);
        }
    }

    public function test_the_list_can_be_filtered_and_searched(): void
    {
        $deed = $this->make($this->w->managerA, ['name' => 'Alpha deed.pdf', 'category' => 'deed']);
        $tax = $this->make($this->w->managerA, ['name' => 'Levy.pdf', 'category' => 'tax']);

        $this->as($this->w->managerA);
        $this->assertSame([$tax], collect($this->getJson('/api/v1/documents?category=tax')->json('data'))->pluck('id')->all());
        $this->assertSame([$deed], collect($this->getJson('/api/v1/documents?search=alpha')->json('data'))->pluck('id')->all());
    }

    // ---- Changing and removing ------------------------------------------------

    public function test_a_manager_can_rename_and_delete_documents_on_their_property(): void
    {
        $id = $this->make($this->w->managerA);

        $this->patchJson("/api/v1/documents/{$id}", ['name' => 'Renamed.pdf'])->assertOk()->assertJsonPath('data.name', 'Renamed.pdf');
        $this->deleteJson("/api/v1/documents/{$id}")->assertOk();

        $this->assertNull(DocumentRecord::query()->find($id));
    }

    public function test_an_admin_can_delete_any_document(): void
    {
        $id = $this->make($this->w->admin, ['propertyId' => 'prop_b']);

        $this->as($this->w->admin)->deleteJson("/api/v1/documents/{$id}")->assertOk();
    }

    public function test_people_without_document_rights_cannot_delete_or_edit(): void
    {
        $id = $this->make($this->w->managerA, ['tenantId' => 'ten_ada', 'category' => 'tenant-id']);

        foreach (['tenantUser', 'financeA', 'ownerA'] as $who) {
            $this->as($this->w->{$who})->deleteJson("/api/v1/documents/{$id}")->assertForbidden();
        }
        foreach (['tenantUser', 'ownerA'] as $who) {
            $this->as($this->w->{$who})->patchJson("/api/v1/documents/{$id}", ['name' => 'Hijacked.pdf'])->assertForbidden();
        }
        $this->assertSame('Title deed.pdf', DocumentRecord::query()->find($id)->name);

        // finance holds the general edit right, so it may rename — but not remove
        $this->as($this->w->financeA)->patchJson("/api/v1/documents/{$id}", ['name' => 'Renamed by finance.pdf'])->assertOk();
    }

    public function test_other_propertys_staff_cannot_touch_it_and_it_cannot_be_moved(): void
    {
        $id = $this->make($this->w->managerA);

        $this->assertContains($this->as($this->w->ownerB)->deleteJson("/api/v1/documents/{$id}")->status(), [403, 404]);
        $this->assertContains($this->patchJson("/api/v1/documents/{$id}", ['name' => 'Hijacked.pdf'])->status(), [403, 404]);

        $this->as($this->w->managerA)->patchJson("/api/v1/documents/{$id}", ['propertyId' => 'prop_b'])->assertStatus(422);
        $this->assertSame('prop_a', DocumentRecord::query()->find($id)->propertyId);
    }

    public function test_deleting_an_unknown_document_is_404(): void
    {
        $this->as($this->w->managerA)->deleteJson('/api/v1/documents/doc_nope')->assertNotFound();
    }
}

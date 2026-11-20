<?php

namespace Tests\Feature\Reporting;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\MaintenancePayout;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\PropertyMember;
use App\Services\AuditService;
use Tests\PmsTestCase;

/** The audit trail records which property each entry is about, and readers only get their own properties'. */
class AuditScopeTest extends PmsTestCase
{
    private function record(string $entity, ?string $entityId): AuditLog
    {
        return app(AuditService::class)->record($this->w->managerA, 'edit', $entity, $entityId, "{$entity} touched");
    }

    public function test_entries_carry_the_property_of_the_record_they_are_about(): void
    {
        MaintenanceRequest::query()->create(['id' => 'mnt_1', 'code' => 'M1', 'propertyId' => 'prop_b', 'unitId' => 'unit_b', 'title' => 't', 'category' => 'c', 'urgency' => 'low', 'status' => 'open']);
        MaintenancePayout::query()->create(['id' => 'po_1', 'code' => 'P1', 'maintenanceId' => 'mnt_1', 'propertyId' => 'prop_b', 'staffId' => 'x', 'amount' => 1, 'status' => 'requested']);
        Expense::query()->create(['id' => 'exp_1', 'code' => 'E1', 'propertyId' => 'prop_b', 'category' => 'repairs', 'description' => 'd', 'amount' => 1, 'date' => '2026-11-01', 'status' => 'pending']);
        Payment::query()->create(['id' => 'pay_1', 'code' => 'PY1', 'invoiceId' => $this->w->invoice->id, 'leaseId' => 'lease_b', 'unitId' => 'unit_b', 'propertyId' => 'prop_b', 'tenantId' => 'ten_bee', 'type' => 'rent', 'method' => 'cash', 'amount' => 1, 'paymentDate' => '2026-11-01', 'reference' => 'r', 'status' => 'approved']);
        $member = PropertyMember::query()->where('propertyId', 'prop_b')->firstOrFail();

        $expected = [
            ['Invoice', $this->w->invoice->id, 'prop_a'], ['Lease', 'lease_b', 'prop_b'], ['Maintenance', 'mnt_1', 'prop_b'],
            ['MaintenancePayout', 'po_1', 'prop_b'], ['Expense', 'exp_1', 'prop_b'], ['Payment', 'pay_1', 'prop_b'],
            ['PropertyMember', $member->id, 'prop_b'], ['Property', 'prop_a', 'prop_a'],
        ];

        foreach ($expected as [$entity, $id, $property]) {
            $this->assertSame($property, $this->record($entity, $id)->propertyId, $entity);
        }
    }

    public function test_account_level_and_unknown_entries_have_no_property(): void
    {
        foreach ([['User', $this->w->managerA->id], ['Settings', null], ['Auth', $this->w->managerA->id], ['Invoice', 'inv_gone'], ['Mystery', 'x']] as [$entity, $id]) {
            $this->assertNull($this->record($entity, $id)->propertyId, $entity);
        }
    }

    public function test_finance_reads_only_the_entries_for_their_properties(): void
    {
        AuditLog::query()->delete();
        app(AuditService::class)->record($this->w->managerA, 'edit', 'Property', 'prop_a', 'A entry');
        app(AuditService::class)->record($this->w->ownerB, 'edit', 'Property', 'prop_b', 'B entry');
        app(AuditService::class)->record($this->w->admin, 'edit', 'Settings', null, 'Account-level entry');

        $summaries = fn ($who) => collect($this->as($this->w->{$who})->getJson('/api/v1/audit?per_page=100')->assertOk()->json('data'))->pluck('summary')->all();

        $this->assertSame(['A entry'], $summaries('financeA'), 'not B’s, and not account-level entries');
        $this->assertEqualsCanonicalizing(['A entry', 'B entry', 'Account-level entry'], $summaries('admin'));
    }

    public function test_only_admin_and_finance_can_open_the_audit_log(): void
    {
        foreach (['ownerA', 'managerA', 'tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who})->getJson('/api/v1/audit')->assertForbidden();
        }
    }

    public function test_the_audit_log_is_read_only(): void
    {
        $entry = $this->record('Property', 'prop_a');

        $this->as($this->w->admin)->putJson("/api/v1/audit/{$entry->id}", ['summary' => 'rewritten'])->assertStatus(422);
        $this->postJson('/api/v1/audit', ['action' => 'edit', 'entity' => 'X', 'summary' => 'forged'])->assertStatus(422);
        $this->assertSame('Property touched', $entry->fresh()->summary);
    }

    public function test_the_dashboard_feed_for_other_roles_is_their_own_actions_on_their_properties(): void
    {
        app(AuditService::class)->record($this->w->managerA, 'edit', 'Property', 'prop_a', 'Manager did this');
        app(AuditService::class)->record($this->w->financeA, 'edit', 'Property', 'prop_a', 'Finance did this');

        $feed = collect($this->as($this->w->managerA)->getJson('/api/v1/dashboard')->json('data.recentActivity'))->pluck('summary')->all();

        $this->assertSame(['Manager did this'], $feed);
    }
}

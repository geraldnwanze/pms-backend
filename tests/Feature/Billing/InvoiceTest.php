<?php

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Notification;
use App\Services\InvoiceService;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;

/** Invoices: creation (totals, line items, notice to the tenant), cancelling, status rules and who may do what. */
class InvoiceTest extends PmsTestCase
{
    private function payload(array $over = []): array
    {
        return array_merge([
            'leaseId' => 'lease_a', 'unitId' => 'unit_a', 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada',
            'issueDate' => '2026-11-01', 'dueDate' => '2026-11-30', 'cycle' => 'monthly',
            'lineItems' => [
                ['description' => 'Rent', 'type' => 'rent', 'amount' => 250_000],
                ['description' => 'Service charge', 'type' => 'service', 'amount' => 50_000],
            ],
        ], $over);
    }

    // ---- Creating -----------------------------------------------------------

    public function test_creating_an_invoice_totals_the_line_items_and_starts_unpaid(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/invoices', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.total', 300_000)
            ->assertJsonPath('data.amountPaid', 0)
            ->assertJsonPath('data.status', 'unpaid')
            ->json('data.id');

        $invoice = Invoice::query()->findOrFail($id);
        $this->assertNotEmpty($invoice->code);
        $this->assertCount(2, $invoice->lineItems);
        $this->assertNotEmpty($invoice->lineItems[0]['id'], 'every line item gets an id');
    }

    public function test_the_client_cannot_set_the_total_the_paid_amount_or_the_status(): void
    {
        $id = $this->as($this->w->managerA)
            ->postJson('/api/v1/invoices', $this->payload(['total' => 1, 'amountPaid' => 999_999, 'status' => 'fully-paid']))
            ->assertCreated()->json('data.id');

        $invoice = Invoice::query()->findOrFail($id);
        $this->assertSame(300_000, (int) $invoice->total);
        $this->assertSame(0, (int) $invoice->amountPaid);
        $this->assertSame('unpaid', $invoice->status);
    }

    public function test_the_tenant_is_told_and_the_creation_is_audited(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/invoices', $this->payload())->json('data.id');

        $note = Notification::query()->where('forUserId', 'ten_ada')->where('title', 'New invoice issued')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('₦300,000', $note->body);
        $this->assertStringContainsString('is due 30 Nov 2026.', $note->body);         // not "2026-11-30"
        $this->assertSame("/portal/bills/{$id}", $note->link);
        $this->assertTrue(AuditLog::query()->where('entityId', $id)->where('action', 'create')->exists());
    }

    public function test_an_invoice_needs_at_least_one_line_item_and_valid_fields(): void
    {
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/invoices', $this->payload(['lineItems' => []]))->assertStatus(422);
        $this->postJson('/api/v1/invoices', $this->payload(['cycle' => 'fortnightly']))->assertStatus(422);
        $this->postJson('/api/v1/invoices', $this->payload(['leaseId' => 'nope']))->assertStatus(422);
        $this->postJson('/api/v1/invoices', $this->payload(['lineItems' => [['description' => 'Rent', 'type' => 'rent', 'amount' => -5]]]))->assertStatus(422);
    }

    public function test_finance_staff_can_raise_invoices_but_owners_hold_no_create_right(): void
    {
        $this->as($this->w->financeA)->postJson('/api/v1/invoices', $this->payload())->assertCreated();
        $this->as($this->w->ownerA)->postJson('/api/v1/invoices', $this->payload())->assertForbidden();   // owners oversee; managers/finance raise
    }

    public function test_an_invoice_cannot_be_tied_to_a_lease_unit_or_tenant_from_another_property(): void
    {
        $before = Invoice::query()->count();
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/invoices', $this->payload(['leaseId' => 'lease_b']))->assertStatus(422);
        $this->postJson('/api/v1/invoices', $this->payload(['unitId' => 'unit_b']))->assertStatus(422);
        $this->postJson('/api/v1/invoices', $this->payload(['tenantId' => 'ten_bee']))->assertStatus(422);

        $this->assertSame($before, Invoice::query()->count());
    }

    public function test_nobody_can_raise_an_invoice_on_a_property_they_are_not_part_of(): void
    {
        $before = Invoice::query()->count();

        $this->as($this->w->managerA)->postJson('/api/v1/invoices', $this->payload(['propertyId' => 'prop_b', 'unitId' => 'unit_b']))->assertStatus(403);
        $this->as($this->w->ownerB)->postJson('/api/v1/invoices', $this->payload())->assertStatus(403);

        $this->assertSame($before, Invoice::query()->count());
    }

    public function test_tenants_and_strangers_cannot_raise_invoices(): void
    {
        foreach (['tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/invoices', $this->payload())->assertForbidden();
        }
    }

    public function test_it_needs_a_login(): void
    {
        $this->postJson('/api/v1/invoices', $this->payload())->assertUnauthorized();
        $this->postJson("/api/v1/invoices/{$this->w->invoice->id}/cancel")->assertUnauthorized();
    }

    // ---- Cancelling ---------------------------------------------------------

    public function test_cancelling_marks_the_invoice_tells_the_tenant_and_is_audited(): void
    {
        $this->as($this->w->managerA)->postJson("/api/v1/invoices/{$this->w->invoice->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', $this->w->invoice->fresh()->status);
        $note = Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Invoice cancelled')->first();
        $this->assertNotNull($note);
        $this->assertSame("/portal/bills/{$this->w->invoice->id}", $note->link);
        $this->assertTrue(AuditLog::query()->where('entityId', $this->w->invoice->id)->where('action', 'edit')->exists());
    }

    public function test_only_staff_of_that_property_can_cancel(): void
    {
        $id = $this->w->invoice->id;

        $this->as($this->w->ownerB)->postJson("/api/v1/invoices/{$id}/cancel")->assertForbidden();
        $this->as($this->w->tenantUser)->postJson("/api/v1/invoices/{$id}/cancel")->assertForbidden();
        $this->as($this->w->stranger)->postJson("/api/v1/invoices/{$id}/cancel")->assertStatus(403);

        $this->assertSame('unpaid', $this->w->invoice->fresh()->status);
    }

    public function test_cancelling_an_unknown_invoice_is_404(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/invoices/inv_nope/cancel')->assertNotFound();
    }

    public function test_a_cancelled_invoice_stays_cancelled_whatever_happens_to_it_later(): void
    {
        $invoice = $this->w->invoice(['status' => 'cancelled', 'dueDate' => '2020-01-01']);

        app(InvoiceService::class)->recompute($invoice);

        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    // ---- Status rules -------------------------------------------------------

    private function statusOf(array $attrs, ?string $today = '2026-11-15'): string
    {
        return app(InvoiceService::class)->statusFor($this->w->invoice($attrs), Carbon::parse($today));
    }

    public function test_status_follows_the_paid_amount(): void
    {
        $this->assertSame('fully-paid', $this->statusOf(['total' => 100_000, 'amountPaid' => 100_000, 'dueDate' => '2026-11-01']));
        $this->assertSame('fully-paid', $this->statusOf(['total' => 100_000, 'amountPaid' => 120_000, 'dueDate' => '2026-11-01']), 'overpaid is still paid');
        $this->assertSame('partially-paid', $this->statusOf(['total' => 100_000, 'amountPaid' => 1, 'dueDate' => '2026-11-01']));
        $this->assertSame('partially-paid', $this->statusOf(['total' => 100_000, 'amountPaid' => 99_999, 'dueDate' => '2026-12-31']));
    }

    public function test_an_unpaid_invoice_is_overdue_only_after_its_due_day_has_passed(): void
    {
        $this->assertSame('unpaid', $this->statusOf(['dueDate' => '2026-11-16']), 'due tomorrow');
        $this->assertSame('unpaid', $this->statusOf(['dueDate' => '2026-11-15']), 'due today is not late yet');
        $this->assertSame('overdue', $this->statusOf(['dueDate' => '2026-11-14']), 'due yesterday');
    }

    public function test_recompute_saves_the_new_status_and_leaves_the_rest_untouched(): void
    {
        $invoice = $this->w->invoice(['total' => 100_000, 'amountPaid' => 100_000, 'status' => 'unpaid']);
        app(InvoiceService::class)->recompute($invoice);
        $this->assertSame('fully-paid', $invoice->fresh()->status);

        $due = $this->w->invoice(['dueDate' => now()->toDateString(), 'status' => 'unpaid']);
        app(InvoiceService::class)->recompute($due);
        $this->assertSame('unpaid', $due->fresh()->status, 'an invoice due today is not overdue');
    }

    // ---- Reading ------------------------------------------------------------

    public function test_the_list_is_scoped_to_the_callers_properties_and_tenants_see_only_their_own(): void
    {
        $other = $this->w->invoice(['id' => 'inv_other', 'propertyId' => 'prop_b', 'unitId' => 'unit_b', 'tenantId' => 'ten_bee']);

        $ids = fn () => collect($this->getJson('/api/v1/invoices?per_page=100')->assertOk()->json('data'))->pluck('id')->all();

        $this->as($this->w->managerA);
        $this->assertContains($this->w->invoice->id, $ids());
        $this->assertNotContains($other->id, $ids());

        $this->as($this->w->ownerB);
        $this->assertContains($other->id, $ids());
        $this->assertNotContains($this->w->invoice->id, $ids());

        $this->as($this->w->tenantUser);
        $this->assertSame([$this->w->invoice->id], $ids());

        $this->as($this->w->stranger)->getJson('/api/v1/invoices')->assertForbidden();   // an account on no property has no billing view
    }

    public function test_filtering_by_status_works(): void
    {
        $this->w->invoice(['id' => 'inv_late', 'status' => 'overdue', 'dueDate' => '2026-01-01']);

        $rows = $this->as($this->w->managerA)->getJson('/api/v1/invoices?status=overdue&per_page=100')->assertOk()->json('data');

        $this->assertSame(['inv_late'], collect($rows)->pluck('id')->all());
    }
}

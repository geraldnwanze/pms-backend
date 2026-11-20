<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Services\DepositService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\PmsTestCase;

/** Settling a deposit can pay the lease's unpaid invoices first, oldest due first, before anything is refunded. */
class DepositOffsetTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 09:00:00');
        Invoice::query()->delete();     // start without the world's default invoice
        Lease::query()->where('id', 'lease_a')->update([
            'securityDeposit' => 500_000, 'status' => 'terminated',
            'depositLedger' => [['id' => 'd1', 'type' => 'received', 'amount' => 500_000, 'date' => '2026-01-01', 'note' => '', 'byId' => 'x']],
        ]);
    }

    private function owe(string $id, string $due, int $total = 100_000, int $paid = 0, array $over = []): Invoice
    {
        return $this->w->invoice(['id' => $id, 'dueDate' => $due, 'total' => $total, 'amountPaid' => $paid, 'status' => $paid > 0 ? 'partially-paid' : 'overdue', ...$over]);
    }

    private function settle(array $body, string $as = 'managerA'): TestResponse
    {
        return $this->as($this->w->{$as})->postJson('/api/v1/leases/lease_a/deposit/settle', $body);
    }

    private function types(): array
    {
        return array_column(Lease::query()->find('lease_a')->depositLedger, 'amount', 'type');
    }

    public function test_arrears_are_paid_oldest_first_and_the_rest_is_refunded(): void
    {
        $this->owe('inv_new', '2026-10-15', 100_000);
        $this->owe('inv_old', '2026-09-15', 150_000, 50_000);   // owes 100,000

        $this->settle(['applyToArrears' => true])->assertOk()->assertJsonPath('data.held', 0);

        $this->assertEqualsCanonicalizing(['inv_old', 'inv_new'], Payment::query()->where('method', 'deposit')->pluck('invoiceId')->all());
        $this->assertSame(['Applied to invoice INV-V_OLD', 'Applied to invoice INV-V_NEW'], collect(Lease::query()->find('lease_a')->depositLedger)->where('type', 'offset')->pluck('note')->all());
        $this->assertSame('fully-paid', Invoice::query()->find('inv_old')->status);
        $this->assertSame(150_000, Invoice::query()->find('inv_old')->amountPaid);
        $this->assertSame('fully-paid', Invoice::query()->find('inv_new')->status);
        $this->assertSame(200_000, (int) collect(Lease::query()->find('lease_a')->depositLedger)->where('type', 'offset')->sum('amount'));
        $this->assertSame(300_000, $this->types()['refund']);
    }

    public function test_deductions_come_first_and_a_shortfall_pays_the_oldest_in_part(): void
    {
        $this->owe('inv_a', '2026-08-15', 300_000);
        $this->owe('inv_b', '2026-09-15', 300_000);

        $this->settle(['applyToArrears' => true, 'deductions' => [['reason' => 'Broken door', 'amount' => 100_000]]])->assertOk();

        $this->assertSame(300_000, Invoice::query()->find('inv_a')->amountPaid);
        $this->assertSame(100_000, Invoice::query()->find('inv_b')->amountPaid);
        $this->assertSame('partially-paid', Invoice::query()->find('inv_b')->status);
        $this->assertArrayNotHasKey('refund', $this->types());       // nothing left to give back
        $this->assertSame(0, DepositService::heldOf(Lease::query()->find('lease_a')->depositLedger));
    }

    public function test_a_late_fee_is_part_of_what_the_deposit_covers(): void
    {
        $this->owe('inv_fee', '2026-10-01', 110_000, 0, ['penalty' => 10_000]);

        $this->settle(['applyToArrears' => true])->assertOk();

        $this->assertSame('fully-paid', Invoice::query()->find('inv_fee')->status);
        $this->assertSame(110_000, (int) $this->types()['offset']);
    }

    public function test_the_payment_it_makes_is_on_record_and_approved(): void
    {
        $this->owe('inv_one', '2026-10-01', 100_000);

        $this->settle(['applyToArrears' => true])->assertOk();

        $pay = Payment::query()->where('invoiceId', 'inv_one')->firstOrFail();
        $this->assertSame(['approved', 'deposit', 100_000, 'ten_ada'], [$pay->status, $pay->method, $pay->amount, $pay->tenantId]);
        $this->assertSame(0, $pay->balanceAfter);
    }

    public function test_nothing_is_applied_unless_asked(): void
    {
        $this->owe('inv_x', '2026-10-01');

        $this->settle([])->assertOk();

        $this->assertSame(0, Invoice::query()->find('inv_x')->amountPaid);
        $this->assertSame(500_000, $this->types()['refund']);
        $this->assertSame(0, Payment::query()->where('method', 'deposit')->count());
    }

    public function test_cancelled_paid_and_awaiting_verification_invoices_are_left_alone(): void
    {
        $this->owe('inv_cancel', '2026-08-01', 100_000, 0, ['status' => 'cancelled']);
        $this->owe('inv_paid', '2026-08-02', 100_000, 100_000, ['status' => 'fully-paid']);
        $this->owe('inv_wait', '2026-08-03');
        Payment::query()->create(['id' => 'pay_w', 'code' => 'PYW', 'invoiceId' => 'inv_wait', 'leaseId' => 'lease_a', 'unitId' => 'unit_a', 'propertyId' => 'prop_a', 'tenantId' => 'ten_ada', 'type' => 'rent', 'method' => 'cash', 'amount' => 100_000, 'paymentDate' => '2026-11-17', 'reference' => 'w', 'status' => 'pending-verification']);
        $this->owe('inv_due', '2026-10-01');

        $this->settle(['applyToArrears' => true])->assertOk();

        $this->assertSame(0, Invoice::query()->find('inv_cancel')->amountPaid);
        $this->assertSame(0, Invoice::query()->find('inv_wait')->amountPaid);
        $this->assertSame(100_000, Invoice::query()->find('inv_due')->amountPaid);
        $this->assertSame(100_000, (int) $this->types()['offset']);
    }

    public function test_other_leases_invoices_are_never_touched(): void
    {
        $this->w->invoice(['id' => 'inv_b', 'leaseId' => 'lease_b', 'propertyId' => 'prop_b', 'unitId' => 'unit_b', 'tenantId' => 'ten_bee', 'dueDate' => '2026-09-01', 'status' => 'overdue']);

        $this->settle(['applyToArrears' => true])->assertOk();

        $this->assertSame(0, Invoice::query()->find('inv_b')->amountPaid);
        $this->assertArrayNotHasKey('offset', $this->types());
    }

    public function test_the_summary_tells_staff_what_is_owed_but_not_the_tenant(): void
    {
        $this->owe('inv_a', '2026-10-01', 100_000, 30_000);

        $this->as($this->w->managerA)->getJson('/api/v1/leases/lease_a/deposit')->assertOk()->assertJsonPath('data.arrears', 70_000);
        $this->as($this->w->tenantUser)->getJson('/api/v1/leases/lease_a/deposit')->assertOk()->assertJsonPath('data.arrears', null);
    }

    public function test_only_those_who_may_settle_can_apply_a_deposit_to_arrears(): void
    {
        $this->owe('inv_a', '2026-10-01');

        $this->settle(['applyToArrears' => true], 'tenantUser')->assertForbidden();
        $this->assertSame(0, Invoice::query()->find('inv_a')->amountPaid);
    }
}

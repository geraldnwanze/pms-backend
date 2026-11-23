<?php

namespace Tests\Feature\Billing;

use App\Mail\UtilityBillMail;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\PropertyMember;
use App\Models\User;
use App\Support\Recipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\PmsTestCase;

/** A tenant is emailed when a utility bill is raised for them, with the lines and a link straight to the bill. */
class UtilityBillMailTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        Invoice::query()->delete();
        Lease::query()->where('id', 'lease_b')->delete();
        Lease::query()->where('id', 'lease_a')->update(['startDate' => '2026-01-01', 'endDate' => '2027-01-01', 'status' => 'active']);

        foreach ([['m1', 'electricity', 'kWh', 60], ['m2', 'water', 'm³', 200]] as [$id, $type, $uom, $rate]) {
            Meter::query()->create([
                'id' => $id, 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => $type, 'meterNumber' => strtoupper($id), 'unitOfMeasure' => $uom,
                'previousReading' => 100, 'currentReading' => $id === 'm1' ? 250 : 110, 'ratePerUnit' => $rate, 'history' => [],
            ]);
        }
    }

    private function bill(string $meter = 'm1')
    {
        return $this->as($this->w->managerA)->postJson("/api/v1/meters/{$meter}/bill");
    }

    public function test_billing_a_meter_emails_the_tenant_with_the_lines_and_a_link_to_the_bill(): void
    {
        $this->bill()->assertCreated();

        $invoice = Invoice::query()->firstOrFail();
        Mail::assertQueued(UtilityBillMail::class, 1);
        Mail::assertQueued(UtilityBillMail::class, function (UtilityBillMail $m) use ($invoice) {
            $m->assertHasSubject("Utility bill: invoice {$invoice->code}");
            $m->assertSeeInHtml('Electricity M1: 150 kWh');
            $m->assertSeeInHtml('₦9,000');
            $m->assertSeeInHtml(config('app.frontend_url')."/portal/bills/{$invoice->id}");
            $m->assertSeeInHtml('Nov 29, 2026');                                                 // due in 14 days

            return $m->hasTo('ada@tenant.test');
        });
    }

    public function test_the_email_does_not_leak_the_rate_formatting_into_the_line(): void
    {
        $this->bill()->assertCreated();

        Mail::assertQueued(UtilityBillMail::class, fn (UtilityBillMail $m) => ! str_contains($m->render(), '₦60.00'));
    }

    public function test_the_final_bill_email_says_so(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/leases/lease_a/terminate')->assertOk();

        $invoice = Invoice::query()->firstOrFail();
        Mail::assertQueued(UtilityBillMail::class, function (UtilityBillMail $m) use ($invoice) {
            $m->assertHasSubject("Your final utility bill: invoice {$invoice->code}");
            $m->assertSeeInHtml('Your tenancy has ended');

            return $m->hasTo('ada@tenant.test');
        });
    }

    public function test_a_bulk_run_emails_each_tenant_once_and_a_dry_run_emails_nobody(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/meters/bill-all', ['dryRun' => true])->assertOk();
        Mail::assertNothingQueued();

        $this->as($this->w->managerA)->postJson('/api/v1/meters/bill-all')->assertOk()->assertJsonPath('data.invoices', 1);

        Mail::assertQueued(UtilityBillMail::class, 1);                                           // both meters, one invoice, one email
    }

    public function test_the_monthly_job_emails_too(): void
    {
        $this->artisan('pms:bill-utilities')->assertSuccessful();

        Mail::assertQueued(UtilityBillMail::class, fn (UtilityBillMail $m) => $m->hasTo('ada@tenant.test'));
    }

    public function test_a_tenant_with_no_login_is_billed_without_an_email(): void
    {
        PropertyMember::query()->where('tenantId', 'ten_ada')->delete();
        User::query()->where('tenantId', 'ten_ada')->update(['tenantId' => null]);

        $this->bill()->assertCreated();

        $this->assertSame(1, Invoice::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_an_email_failure_never_undoes_the_bill(): void
    {
        Exceptions::fake();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('mail is down'));

        $this->bill()->assertCreated();

        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(250.0, Meter::query()->find('m1')->billedReading);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_looking_up_many_tenants_at_once_gives_the_same_people_as_one_at_a_time(): void
    {
        $other = User::query()->create(['id' => 'usr_member', 'name' => 'Ada Spouse', 'email' => 'spouse@tenant.test', 'password' => 'x', 'role' => 'tenant']);
        PropertyMember::query()->create(['id' => 'pm_spouse', 'propertyId' => 'prop_a', 'userId' => $other->id, 'email' => $other->email, 'tenantId' => 'ten_ada', 'role' => 'tenant', 'status' => 'active']);

        $together = Recipients::tenantUsersByTenant(['ten_ada', 'ten_nobody']);

        $this->assertSame(Recipients::tenantUsers('ten_ada')->pluck('id')->sort()->values()->all(), $together['ten_ada']->pluck('id')->sort()->values()->all());
        $this->assertContains('usr_member', $together['ten_ada']->pluck('id')->all());
        $this->assertTrue($together['ten_nobody']->isEmpty());
        $this->assertTrue(Recipients::tenantUsersByTenant([])->isEmpty());
    }
}

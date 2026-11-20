<?php

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Meter;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UtilityBillRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\PmsTestCase;

/** "Bill everything that is ready": one invoice per unit, only for what the person manages, and a way to look first. */
class BulkUtilityBillingTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        Invoice::query()->delete();

        // A second unit on property A, with its own tenant. Property B (unit_b, Bee) stays as it is.
        Unit::query()->create(['id' => 'unit_a2', 'buildingId' => 'bld_a', 'propertyId' => 'prop_a', 'code' => 'UNIT_A2', 'type' => '1-Bedroom Flat', 'floor' => 1, 'status' => 'occupied', 'rent' => 900_000]);
        Tenant::query()->create(['id' => 'ten_cy', 'name' => 'Cy Eze', 'email' => 'cy@tenant.test', 'phone' => '0800000002', 'createdAt' => '2026-01-01']);
        Lease::query()->create([
            'id' => 'lease_a2', 'code' => 'LSE-A2', 'unitId' => 'unit_a2', 'propertyId' => 'prop_a', 'tenantId' => 'ten_cy',
            'startDate' => '2026-05-01', 'endDate' => '2027-04-30', 'rent' => 900_000, 'billingCycle' => 'annual', 'status' => 'active', 'signed' => true,
        ]);

        $this->meter('m1', 'unit_a', 'prop_a');                                                                              // 150 × 60 = 9,000
        $this->meter('m2', 'unit_a', 'prop_a', ['type' => 'water', 'unitOfMeasure' => 'm³', 'previousReading' => 10, 'currentReading' => 20.5, 'ratePerUnit' => 200]);   // 2,100
        $this->meter('m3', 'unit_a2', 'prop_a', ['previousReading' => 0, 'currentReading' => 50]);                         // 3,000
        $this->meter('m4', 'unit_b', 'prop_b');                                                                              // 9,000, property B
    }

    private function meter(string $id, string $unit, string $property, array $over = []): Meter
    {
        return Meter::query()->create($over + [
            'id' => $id, 'propertyId' => $property, 'unitId' => $unit, 'type' => 'electricity', 'meterNumber' => strtoupper($id),
            'unitOfMeasure' => 'kWh', 'previousReading' => 100, 'currentReading' => 250, 'ratePerUnit' => 60, 'history' => [],
        ]);
    }

    private function billAll(array $body = [], $as = null)
    {
        return $this->as($as ?? $this->w->managerA)->postJson('/api/v1/meters/bill-all', $body);
    }

    public function test_a_dry_run_says_who_would_be_billed_for_what_and_changes_nothing(): void
    {
        $r = $this->billAll(['dryRun' => true])->assertOk()->json('data');

        $this->assertTrue($r['dryRun']);
        $this->assertSame(0, $r['invoices']);
        $this->assertSame(3, $r['meters']);
        $this->assertSame(14_100, $r['total']);
        $this->assertCount(2, $r['units']);                                     // property B is not this manager's
        $a = collect($r['units'])->firstWhere('unitId', 'unit_a');
        $this->assertSame('Ada Obi', $a['tenantName']);
        $this->assertSame('Alpha Court', $a['propertyName']);                    // unit codes repeat across properties
        $this->assertSame('LSE-A', $a['leaseCode']);
        $this->assertSame(11_100, $a['total']);
        $this->assertNull($a['invoiceId']);
        $this->assertSame(['M1', 'M2'], array_column($a['meters'], 'meterNumber'));
        $this->assertEquals([100, 250, 150, 60, 9_000], [$a['meters'][0]['from'], $a['meters'][0]['to'], $a['meters'][0]['units'], $a['meters'][0]['rate'], $a['meters'][0]['amount']]);

        $this->assertSame(0, Invoice::query()->count());
        $this->assertNull(Meter::query()->find('m1')->billedReading);
    }

    public function test_billing_raises_one_invoice_per_unit_moves_the_markers_and_tells_each_tenant(): void
    {
        $r = $this->billAll()->assertOk()->json('data');

        $this->assertFalse($r['dryRun']);
        $this->assertSame(2, $r['invoices']);
        $this->assertSame(14_100, $r['total']);
        $this->assertEqualsCanonicalizing([11_100, 3_000], Invoice::query()->pluck('total')->all());
        $this->assertNotNull(collect($r['units'])->firstWhere('unitId', 'unit_a')['invoiceCode']);
        $this->assertSame(250.0, Meter::query()->find('m1')->billedReading);
        $this->assertNull(Meter::query()->find('m4')->billedReading);           // another property's meter is left alone
        $this->assertTrue(Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Utility bill issued')->exists());
        $this->assertTrue(Notification::query()->where('forUserId', 'ten_cy')->where('title', 'Utility bill issued')->exists());
    }

    public function test_doing_it_again_bills_nothing_twice(): void
    {
        $this->billAll()->assertOk();

        $r = $this->billAll()->assertOk()->json('data');

        $this->assertSame(0, $r['invoices']);
        $this->assertSame([], $r['units']);
        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_a_unit_with_nobody_in_it_is_reported_and_left_for_later_not_written_off(): void
    {
        Lease::query()->where('id', 'lease_a2')->update(['status' => 'terminated']);

        $r = $this->billAll()->assertOk()->json('data');

        $this->assertSame(1, $r['invoices']);
        $this->assertSame('unit_a2', $r['skipped'][0]['unitId']);
        $this->assertSame('UNIT_A2', $r['skipped'][0]['unitCode']);
        $this->assertSame('Alpha Court', $r['skipped'][0]['propertyName']);
        $this->assertStringContainsString('No tenant', $r['skipped'][0]['reason']);
        $this->assertNull(Meter::query()->find('m3')->billedReading);            // unlike the monthly job, nothing is quietly written off
    }

    public function test_a_meter_that_cannot_be_priced_is_reported_and_the_rest_still_billed(): void
    {
        Meter::query()->where('id', 'm2')->update(['ratePerUnit' => 0]);

        $r = $this->billAll()->assertOk()->json('data');

        $this->assertSame(2, $r['invoices']);
        $this->assertSame(9_000 + 3_000, $r['total']);
        $this->assertCount(1, $r['skipped']);
        $this->assertStringContainsString('no rate is set', $r['skipped'][0]['reason']);
        $this->assertNull(Meter::query()->find('m2')->billedReading);
    }

    public function test_it_can_be_narrowed_to_some_properties(): void
    {
        $all = $this->billAll(['dryRun' => true], $this->w->admin)->assertOk()->json('data');
        $this->assertSame(3, $all['invoices'] + count($all['units']));          // admin sees property B too: three units

        $a = $this->billAll(['propertyIds' => ['prop_a']], $this->w->admin)->assertOk()->json('data');

        $this->assertSame(2, $a['invoices']);
        $this->assertNull(Meter::query()->find('m4')->billedReading);
    }

    public function test_someone_cannot_bill_a_property_they_do_not_manage_by_naming_it(): void
    {
        $r = $this->billAll(['propertyIds' => ['prop_b']])->assertOk()->json('data');

        $this->assertSame(0, $r['invoices']);
        $this->assertNull(Meter::query()->find('m4')->billedReading);
    }

    public function test_people_who_do_not_manage_meters_cannot_use_it(): void
    {
        $this->billAll([], $this->w->tenantUser)->assertForbidden();
        $this->billAll([], $this->w->stranger)->assertForbidden();
        $this->billAll([], $this->w->financeA)->assertForbidden();
        $this->billAll([], $this->w->ownerA)->assertForbidden();         // owners cannot edit meters, so they cannot bill them either
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_the_request_is_validated(): void
    {
        $this->billAll(['propertyIds' => 'prop_a'])->assertUnprocessable();
        $this->billAll(['dryRun' => 'maybe'])->assertUnprocessable();
    }

    // ---- history -----------------------------------------------------------------------

    private function runs()
    {
        return AuditLog::query()->where('summary', 'like', 'Bulk-billed%')->orderBy('entityId')->get();
    }

    public function test_a_run_leaves_one_line_in_the_audit_trail_for_the_property_it_billed(): void
    {
        $this->billAll()->assertOk();

        $runs = $this->runs();
        $this->assertCount(1, $runs);
        $this->assertSame('Property', $runs[0]->entity);
        $this->assertSame('prop_a', $runs[0]->entityId);
        $this->assertSame('prop_a', $runs[0]->propertyId);
        $this->assertSame($this->w->managerA->id, $runs[0]->userId);
        $this->assertSame('Bulk-billed utilities: 2 invoices (₦14,100) on Alpha Court', $runs[0]->summary);
    }

    public function test_a_run_across_properties_leaves_a_line_for_each(): void
    {
        $this->billAll([], $this->w->admin)->assertOk();

        $this->assertSame(['prop_a', 'prop_b'], $this->runs()->pluck('entityId')->all());
        $this->assertSame('Bulk-billed utilities: 1 invoice (₦9,000) on Beta Heights', $this->runs()[1]->summary);
    }

    public function test_the_line_says_how_many_units_were_left_out(): void
    {
        Lease::query()->where('id', 'lease_a2')->update(['status' => 'terminated']);

        $this->billAll()->assertOk();

        $this->assertSame('Bulk-billed utilities: 1 invoice (₦11,100) on Alpha Court; 1 unit skipped', $this->runs()[0]->summary);
    }

    public function test_looking_first_or_billing_nothing_leaves_no_line(): void
    {
        $this->billAll(['dryRun' => true])->assertOk();
        $this->assertCount(0, $this->runs());

        $this->billAll()->assertOk();
        $this->billAll()->assertOk();                                             // nothing left to bill the second time

        $this->assertCount(1, $this->runs());
    }

    public function test_finance_and_admins_can_find_the_line_in_the_audit_trail(): void
    {
        $this->billAll()->assertOk();

        $find = fn ($who) => collect($this->as($this->w->{$who})->getJson('/api/v1/audit?per_page=100&search=Bulk-billed')->assertOk()->json('data'))->pluck('summary')->all();

        $this->assertCount(1, $find('financeA'));
        $this->assertCount(1, $find('admin'));
        $this->as($this->w->ownerB)->getJson('/api/v1/audit')->assertForbidden();     // who sees which property's entries is covered by AuditScopeTest
    }

    // ---- recent runs -------------------------------------------------------------------

    private function runsList(array $query = [], $as = null)
    {
        return $this->as($as ?? $this->w->managerA)->getJson('/api/v1/meters/bill-runs'.($query ? '?'.http_build_query($query) : ''));
    }

    public function test_a_run_shows_up_in_the_list_with_who_ran_it_and_the_figures(): void
    {
        $this->billAll()->assertOk();

        $rows = $this->runsList()->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('prop_a', $rows[0]['propertyId']);
        $this->assertSame('Alpha Court', $rows[0]['propertyName']);
        $this->assertSame($this->w->managerA->id, $rows[0]['userId']);
        $this->assertSame($this->w->managerA->name, $rows[0]['userName']);
        $this->assertSame(2, $rows[0]['invoices']);
        $this->assertSame(14_100, $rows[0]['total']);
        $this->assertSame(0, $rows[0]['unitsSkipped']);
        $this->assertNotEmpty($rows[0]['createdAt']);
    }

    public function test_the_newest_run_comes_first_and_the_list_can_be_shortened(): void
    {
        $this->billAll()->assertOk();
        Carbon::setTestNow('2026-12-01 09:00:00');
        $this->as($this->w->managerA)->postJson('/api/v1/meters/m3/readings', ['value' => 80, 'date' => '2026-12-01'])->assertCreated();
        $this->billAll()->assertOk();

        $rows = $this->runsList()->assertOk()->json('data');

        $this->assertSame([1, 2], array_column($rows, 'invoices'));             // December's one invoice, then November's two
        $this->assertSame([1_800, 14_100], array_column($rows, 'total'));          // 30 kWh since the 50 it was billed up to
        $this->assertCount(1, $this->runsList(['limit' => 1])->assertOk()->json('data'));
        $this->runsList(['limit' => 0])->assertUnprocessable();
        $this->runsList(['limit' => 500])->assertUnprocessable();
    }

    public function test_each_person_sees_only_the_runs_on_their_own_properties(): void
    {
        $this->billAll([], $this->w->admin)->assertOk();                        // property A and property B in one run

        $this->assertSame(['prop_a'], array_column($this->runsList()->assertOk()->json('data'), 'propertyId'));
        $this->assertEqualsCanonicalizing(['prop_a', 'prop_b'], array_column($this->runsList([], $this->w->admin)->assertOk()->json('data'), 'propertyId'));
    }

    public function test_looking_first_or_billing_nothing_adds_nothing_to_the_list(): void
    {
        $this->billAll(['dryRun' => true])->assertOk();
        $this->assertSame([], $this->runsList()->assertOk()->json('data'));

        $this->billAll()->assertOk();
        $this->billAll()->assertOk();                                           // nothing left the second time

        $this->assertSame(1, UtilityBillRun::query()->count());
    }

    public function test_only_the_people_who_can_bill_can_read_the_list(): void
    {
        $this->billAll()->assertOk();

        foreach (['tenantUser', 'stranger', 'financeA', 'ownerA'] as $who) {
            $this->runsList([], $this->w->{$who})->assertForbidden();
        }
    }

    // ---- when something fails mid-run --------------------------------------------------------------------------

    private function failWhen(string $model, ?callable $only = null): void
    {
        Event::listen("eloquent.creating: {$model}", function ($row) use ($only) {
            if (! $only || $only($row)) {
                throw new RuntimeException('boom');
            }
        });
    }

    public function test_one_unit_failing_does_not_stop_the_run_or_hide_what_was_billed(): void
    {
        Exceptions::fake();
        $this->failWhen(Invoice::class, fn ($invoice) => $invoice->unitId === 'unit_a2');

        $r = $this->billAll()->assertOk()->json('data');

        $this->assertSame(1, $r['invoices']);                                      // unit A was billed
        $this->assertSame(11_100, $r['total']);
        $this->assertSame('unit_a2', $r['skipped'][0]['unitId']);
        $this->assertStringContainsString('Could not be billed', $r['skipped'][0]['reason']);
        $this->assertNull(Meter::query()->find('m3')->billedReading);              // the failed unit rolled back untouched …
        $this->assertSame(250.0, Meter::query()->find('m1')->billedReading);       // … and the other was billed
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'boom');

        // The run is on record, with the unit that failed counted as skipped.
        $this->assertSame([1, 11_100, 1], [UtilityBillRun::query()->value('invoices'), (int) UtilityBillRun::query()->value('total'), UtilityBillRun::query()->value('unitsSkipped')]);
        $this->assertSame('Bulk-billed utilities: 1 invoice (₦11,100) on Alpha Court; 1 unit skipped', $this->runs()[0]->summary);

        // Trying again picks up exactly the unit that failed.
        Event::forget('eloquent.creating: '.Invoice::class);
        $again = $this->billAll()->assertOk()->json('data');
        $this->assertSame([1, 3_000], [$again['invoices'], $again['total']]);
        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_a_notice_that_cannot_be_sent_does_not_undo_or_hide_the_bills(): void
    {
        Exceptions::fake();
        $this->failWhen(Notification::class);                                      // e.g. the broadcaster is down

        $r = $this->billAll()->assertOk()->json('data');

        $this->assertSame(2, $r['invoices']);                                      // both invoices exist and are reported as billed
        $this->assertSame(2, Invoice::query()->count());
        $this->assertSame(250.0, Meter::query()->find('m1')->billedReading);
        $this->assertSame([], $r['skipped']);
        $this->assertCount(1, $this->runs());                                      // and the run is on record
        $this->assertSame(1, UtilityBillRun::query()->count());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_billing_one_meter_by_hand_still_succeeds_when_its_notice_fails(): void
    {
        Exceptions::fake();
        $this->failWhen(Notification::class);

        $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/bill')->assertCreated()->assertJsonPath('data.total', 9_000);

        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(250.0, Meter::query()->find('m1')->billedReading);       // not "billing failed" about an invoice that exists
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_the_bills_stand_even_if_the_run_history_cannot_be_written(): void
    {
        Exceptions::fake();
        $this->failWhen(UtilityBillRun::class);

        $r = $this->billAll()->assertOk()->json('data');

        $this->assertSame(2, $r['invoices']);
        $this->assertSame(2, Invoice::query()->count());
        $this->assertCount(1, $this->runs());                                      // the audit line is still written
        $this->assertSame(0, UtilityBillRun::query()->count());
        Exceptions::assertReported(RuntimeException::class);
    }

    // ---- the number of queries does not grow with the number of units ------------------------------------------

    private function moreUnits(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Unit::query()->create(['id' => "ux{$i}", 'buildingId' => 'bld_a', 'propertyId' => 'prop_a', 'code' => "UX{$i}", 'type' => '1-Bedroom Flat', 'floor' => 1, 'status' => 'occupied', 'rent' => 900_000]);
            Tenant::query()->create(['id' => "tx{$i}", 'name' => "Tenant {$i}", 'email' => "tx{$i}@tenant.test", 'phone' => '0800000000', 'createdAt' => '2026-01-01']);
            Lease::query()->create([
                'id' => "lx{$i}", 'code' => "LX{$i}", 'unitId' => "ux{$i}", 'propertyId' => 'prop_a', 'tenantId' => "tx{$i}",
                'startDate' => '2026-05-01', 'endDate' => '2027-04-30', 'rent' => 900_000, 'billingCycle' => 'annual', 'status' => 'active', 'signed' => true,
            ]);
            $this->meter("mx{$i}", "ux{$i}", 'prop_a', ['previousReading' => 0, 'currentReading' => 50]);
        }
    }

    /** @return array<string,int> reads per table while $do runs */
    private function readsWhile(callable $do): array
    {
        $reads = [];
        DB::listen(function ($q) use (&$reads) {
            if (preg_match('/\bfrom ["`]?(\w+)["`]?/i', $q->sql, $m)) {
                $reads[$m[1]] = ($reads[$m[1]] ?? 0) + 1;
            }
        });
        $do();

        return $reads;
    }

    public function test_finding_who_holds_each_unit_and_who_they_are_takes_one_query_each_however_many_units(): void
    {
        $this->moreUnits(12);

        foreach ([['dryRun' => true], []] as $body) {
            $reads = $this->readsWhile(fn () => $this->billAll($body)->assertOk());

            $this->assertLessThanOrEqual(1, $reads['leases'] ?? 0, 'leases: '.json_encode($reads));
            $this->assertLessThanOrEqual(1, $reads['tenants'] ?? 0, 'tenants: '.json_encode($reads));
            $this->assertLessThanOrEqual(1, $reads['units'] ?? 0, 'units: '.json_encode($reads));
        }
    }

    public function test_checking_who_may_bill_does_not_cost_queries_per_meter(): void
    {
        $few = $this->readsWhile(fn () => $this->billAll(['dryRun' => true])->assertOk());
        $this->moreUnits(12);
        $many = $this->readsWhile(fn () => $this->billAll(['dryRun' => true])->assertOk());

        // Twelve more meters on the same property add no membership lookups.
        $this->assertSame($few['property_members'] ?? 0, $many['property_members'] ?? 0, 'few: '.json_encode($few).' many: '.json_encode($many));
    }

    private function queriesWhile(callable $do): int
    {
        $total = 0;
        DB::listen(function () use (&$total) {
            $total++;
        });
        $do();

        return $total;
    }

    public function test_a_dry_run_costs_at_most_one_query_for_each_extra_unit(): void
    {
        $few = $this->queriesWhile(fn () => $this->billAll(['dryRun' => true])->assertOk());
        $this->moreUnits(10);
        $many = $this->queriesWhile(fn () => $this->billAll(['dryRun' => true])->assertOk());

        $this->assertLessThanOrEqual(10, $many - $few, "few={$few} many={$many}");      // the one locked read of that unit's meters
    }

    public function test_a_real_run_costs_a_small_fixed_number_of_queries_for_each_extra_unit(): void
    {
        $few = $this->queriesWhile(fn () => $this->billAll()->assertOk());
        $this->moreUnits(10);
        $many = $this->queriesWhile(fn () => $this->billAll()->assertOk());

        // About seven a unit, all of them the work itself: the locked read, the invoice, the markers, the audit entry and
        // the tenant's notice. Looking up the lease, tenant and unit again for each unit added four more.
        $this->assertLessThanOrEqual(10 * 8, $many - $few, "few={$few} many={$many}");
    }
}

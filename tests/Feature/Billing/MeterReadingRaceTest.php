<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Meter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\PmsTestCase;

/**
 * Logging a reading while something else changes the same meter must not write from a stale copy. SQLite has no row
 * locks to race against, so each test makes the "something else" happen at the exact moment the request has loaded the
 * meter — after its first read, before it saves — which is the interleaving the lock exists to survive.
 */
class MeterReadingRaceTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        Invoice::query()->delete();

        Meter::query()->create([
            'id' => 'm1', 'propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => 'electricity', 'meterNumber' => 'M1',
            'unitOfMeasure' => 'kWh', 'previousReading' => 100, 'currentReading' => 250, 'ratePerUnit' => 60,
            'history' => [['date' => '2026-10-15', 'value' => 100], ['date' => '2026-11-01', 'value' => 250]],
        ]);
    }

    /** Run $thing once, right after the meter is first loaded by the request. */
    private function whenFirstLoaded(callable $thing): void
    {
        $done = false;
        Event::listen('eloquent.retrieved: '.Meter::class, function () use (&$done, $thing) {
            if (! $done) {
                $done = true;
                $thing();
            }
        });
    }

    private function log(float $value)
    {
        return $this->as($this->w->managerA)->postJson('/api/v1/meters/m1/readings', ['value' => $value, 'date' => '2026-11-15']);
    }

    public function test_a_reading_does_not_undo_a_bill_that_lands_while_it_is_being_logged(): void
    {
        // Billing runs in the gap: the tenant is billed up to 250 (marker moves from "never billed" to 250).
        $this->whenFirstLoaded(fn () => DB::table('meters')->where('id', 'm1')->update(['billedReading' => 250, 'billedAt' => '2026-11-15']));

        $this->log(300)->assertCreated();

        $m = Meter::query()->find('m1');
        $this->assertSame(250.0, $m->billedReading, 'the marker the bill set must survive');
        $this->assertSame(300.0, $m->currentReading);
        $this->assertSame(50.0, $m->unbilledUnits);                              // only the new 50, so nothing is billed twice
    }

    public function test_two_readings_logged_at_once_both_end_up_in_the_history(): void
    {
        // Another reading (300) lands in the gap.
        $this->whenFirstLoaded(fn () => DB::table('meters')->where('id', 'm1')->update([
            'previousReading' => 250, 'currentReading' => 300,
            'history' => json_encode([['date' => '2026-10-15', 'value' => 100], ['date' => '2026-11-01', 'value' => 250], ['date' => '2026-11-14', 'value' => 300]]),
        ]));

        $this->log(350)->assertCreated();

        $m = Meter::query()->find('m1');
        $this->assertSame([100.0, 250.0, 300.0, 350.0], array_map('floatval', array_column($m->history, 'value')));
        $this->assertSame([300.0, 350.0], [$m->previousReading, $m->currentReading]);   // rolled from 300, not from a stale 250
    }

    public function test_a_plain_reading_still_rolls_the_meter_forward(): void
    {
        $this->log(300)->assertCreated()->assertJsonPath('data.currentReading', 300)->assertJsonPath('data.previousReading', 250);

        $this->assertCount(3, Meter::query()->find('m1')->history);
        $this->assertSame(100.0, Meter::query()->find('m1')->billedReading);       // a never-billed meter keeps its starting point
    }

    public function test_a_reading_is_still_refused_for_someone_who_may_not_edit_the_meter(): void
    {
        $this->as($this->w->tenantUser)->postJson('/api/v1/meters/m1/readings', ['value' => 300, 'date' => '2026-11-15'])->assertForbidden();
        $this->as($this->w->ownerB)->postJson('/api/v1/meters/m1/readings', ['value' => 300, 'date' => '2026-11-15'])->assertForbidden();

        $this->assertSame(250.0, Meter::query()->find('m1')->currentReading);
    }
}

<?php

namespace Tests\Feature\Operations;

use App\Models\CalendarEvent;
use App\Models\Inspection;
use App\Models\Meter;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\PmsTestCase;
use Tests\Support\World;

/** Meter readings, move-in/move-out inspections and the calendar. */
class MeterInspectionAndEventTest extends PmsTestCase
{
    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');

        $this->tech = World::user('maintenance', 'tech.a@pms.test');
        World::member($this->w->pA, $this->tech, 'maintenance');
    }

    // ======================= Meters ===========================================

    private function meter(string $id = 'mtr_a', string $property = 'prop_a', ?string $unit = 'unit_a', array $over = []): Meter
    {
        return Meter::query()->create($over + [
            'id' => $id, 'propertyId' => $property, 'unitId' => $unit, 'type' => 'electricity', 'meterNumber' => strtoupper($id),
            'unitOfMeasure' => 'kWh', 'previousReading' => 0, 'currentReading' => 100, 'ratePerUnit' => 68.5, 'history' => [],
        ]);
    }

    private function read(User $by, Meter $m, $value, string $date = '2026-11-15')
    {
        return $this->as($by)->postJson("/api/v1/meters/{$m->id}/readings", ['value' => $value, 'date' => $date]);
    }

    public function test_a_new_reading_becomes_current_the_old_one_becomes_previous_and_it_goes_in_the_history(): void
    {
        $m = $this->meter();

        $this->read($this->w->managerA, $m, 160, '2026-11-01')->assertCreated()->assertJsonPath('data.currentReading', 160)->assertJsonPath('data.previousReading', 100);
        $this->read($this->w->managerA, $m, 235.5, '2026-11-15')->assertCreated();

        $fresh = $m->fresh();
        $this->assertSame([235.5, 160.0], [(float) $fresh->currentReading, (float) $fresh->previousReading]);
        $this->assertSame([['date' => '2026-11-01', 'value' => 160], ['date' => '2026-11-15', 'value' => 235.5]], $fresh->history);
    }

    public function test_the_amount_used_between_readings_is_what_the_screen_will_charge_for(): void
    {
        $m = $this->meter(over: ['currentReading' => 1_000]);

        $res = $this->read($this->w->managerA, $m, 1_340)->assertCreated();

        $this->assertSame(340.0, (float) $res->json('data.currentReading') - (float) $res->json('data.previousReading'));
        $this->assertSame(68.5, (float) $res->json('data.ratePerUnit'), 'recording a reading does not touch the rate');
    }

    public function test_a_reading_needs_a_number_of_zero_or_more_and_a_real_date(): void
    {
        $m = $this->meter();
        $this->as($this->w->managerA);

        $this->postJson("/api/v1/meters/{$m->id}/readings", ['date' => '2026-11-15'])->assertStatus(422);
        $this->postJson("/api/v1/meters/{$m->id}/readings", ['value' => 5])->assertStatus(422);
        $this->postJson("/api/v1/meters/{$m->id}/readings", ['value' => 'lots', 'date' => '2026-11-15'])->assertStatus(422);
        $this->postJson("/api/v1/meters/{$m->id}/readings", ['value' => -1, 'date' => '2026-11-15'])->assertStatus(422);
        $this->postJson("/api/v1/meters/{$m->id}/readings", ['value' => 5, 'date' => 'soon'])->assertStatus(422);
        $this->assertSame([], $m->fresh()->history);

        $this->postJson("/api/v1/meters/{$m->id}/readings", ['value' => 0, 'date' => '2026-11-15'])->assertCreated();    // a meter that has just been reset
    }

    public function test_history_and_readings_cannot_be_rewritten_through_a_plain_edit(): void
    {
        $m = $this->meter();
        $this->read($this->w->managerA, $m, 150)->assertCreated();

        $this->as($this->w->managerA);
        $this->patchJson("/api/v1/meters/{$m->id}", ['history' => []])->assertStatus(422);
        $this->patchJson("/api/v1/meters/{$m->id}", ['currentReading' => 1])->assertStatus(422);
        $this->patchJson("/api/v1/meters/{$m->id}", ['previousReading' => 1])->assertStatus(422);

        $this->assertSame([150.0, 100.0], [(float) $m->fresh()->currentReading, (float) $m->fresh()->previousReading]);
        $this->assertCount(1, $m->fresh()->history);
        $this->patchJson("/api/v1/meters/{$m->id}", ['ratePerUnit' => 70, 'meterNumber' => 'NEW-NO'])->assertOk();   // the rest is still editable
    }

    public function test_re_sending_unchanged_readings_in_an_edit_is_fine(): void
    {
        $m = $this->meter();
        $this->read($this->w->managerA, $m, 150)->assertCreated();
        $fresh = $m->fresh();

        $this->as($this->w->managerA)->patchJson("/api/v1/meters/{$m->id}", ['currentReading' => 150, 'previousReading' => 100, 'history' => $fresh->history, 'ratePerUnit' => 71])->assertOk();
    }

    public function test_a_new_meter_starts_with_an_empty_history(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/meters', ['propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => 'water', 'meterNumber' => 'W-1', 'unitOfMeasure' => 'm3', 'currentReading' => 12])
            ->assertCreated()->assertJsonPath('data.history', [])->assertJsonPath('data.currentReading', 12);
    }

    public function test_a_meter_can_start_from_a_fractional_reading_and_keeps_it(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/meters', ['propertyId' => 'prop_a', 'unitId' => 'unit_a', 'type' => 'water', 'meterNumber' => 'W-2', 'unitOfMeasure' => 'm3', 'previousReading' => 3.25, 'currentReading' => 12.75])
            ->assertCreated()->assertJsonPath('data.currentReading', 12.75)->assertJsonPath('data.previousReading', 3.25)->json('data.id');

        $this->assertSame(12.75, (float) Meter::query()->find($id)->currentReading);
    }

    public function test_a_meter_needs_a_known_type_a_number_and_a_unit_of_measure(): void
    {
        $ok = ['propertyId' => 'prop_a', 'type' => 'gas', 'meterNumber' => 'G-1', 'unitOfMeasure' => 'm3'];
        $this->as($this->w->managerA);

        foreach (['propertyId', 'type', 'meterNumber', 'unitOfMeasure'] as $field) {
            $payload = $ok;
            unset($payload[$field]);
            $this->postJson('/api/v1/meters', $payload)->assertStatus(422);
        }
        $this->postJson('/api/v1/meters', ['type' => 'steam'] + $ok)->assertStatus(422);
        $this->postJson('/api/v1/meters', ['previousReading' => -5] + $ok)->assertStatus(422);
    }

    public function test_who_may_record_readings(): void
    {
        $m = $this->meter();

        $this->read($this->w->managerA, $m, 110)->assertCreated();
        $this->read($this->w->admin, $m, 120)->assertCreated();
        foreach (['ownerA', 'tenantUser', 'financeA', 'stranger'] as $who) {
            $this->read($this->w->{$who}, $m, 999)->assertForbidden();
        }
        $this->assertContains($this->read($this->w->ownerB, $m, 999)->status(), [403, 404]);
        $this->assertSame(120.0, (float) $m->fresh()->currentReading);
    }

    public function test_readings_on_an_unknown_meter_are_a_404(): void
    {
        $this->as($this->w->managerA)->postJson('/api/v1/meters/mtr_nope/readings', ['value' => 1, 'date' => '2026-11-15'])->assertNotFound();
    }

    public function test_meters_are_scoped_by_property_and_a_tenant_gets_only_their_own_units(): void
    {
        $mine = $this->meter('mtr_a');
        $theirs = $this->meter('mtr_b', 'prop_b', 'unit_b');
        $common = $this->meter('mtr_common', 'prop_a', null);             // a meter for the building, not a unit

        $ids = fn (User $u) => collect($this->as($u)->getJson('/api/v1/meters?per_page=100')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$mine->id, $common->id], $ids($this->w->managerA));
        $this->assertSame([$theirs->id], $ids($this->w->ownerB));
        $this->assertSame([$mine->id], $ids($this->w->tenantUser));         // their unit's meter — not the building's, not other units'
        $this->as($this->w->stranger)->getJson('/api/v1/meters')->assertForbidden();
    }

    public function test_meters_can_be_filtered_by_type_and_found_by_number(): void
    {
        $this->meter('mtr_e', 'prop_a', 'unit_a', ['type' => 'electricity', 'meterNumber' => 'EL-777']);
        $this->meter('mtr_w', 'prop_a', 'unit_a', ['type' => 'water', 'meterNumber' => 'WA-123']);

        $ids = fn (string $q) => collect($this->as($this->w->managerA)->getJson("/api/v1/meters?per_page=100{$q}")->json('data'))->pluck('id')->all();

        $this->assertSame(['mtr_w'], $ids('&type=water'));
        $this->assertSame(['mtr_e'], $ids('&search=el-777'));
    }

    // ======================= Inspections ======================================

    private function inspection(array $over = []): array
    {
        return $over + ['propertyId' => 'prop_a', 'unitId' => 'unit_a', 'tenantId' => 'ten_ada', 'type' => 'move-in', 'scheduledDate' => '2026-11-20'];
    }

    public function test_an_inspection_starts_scheduled_with_a_code(): void
    {
        $res = $this->as($this->w->managerA)->postJson('/api/v1/inspections', $this->inspection())->assertCreated()->assertJsonPath('data.status', 'scheduled');

        $this->assertStringStartsWith('INS-', $res->json('data.code'));
    }

    public function test_an_inspection_needs_a_type_a_date_and_a_known_status(): void
    {
        $this->as($this->w->managerA);

        foreach (['type', 'scheduledDate', 'unitId', 'propertyId'] as $field) {
            $payload = $this->inspection();
            unset($payload[$field]);
            $this->postJson('/api/v1/inspections', $payload)->assertStatus(422);
        }
        $this->postJson('/api/v1/inspections', $this->inspection(['type' => 'surprise']))->assertStatus(422);
        $this->postJson('/api/v1/inspections', $this->inspection(['status' => 'done']))->assertStatus(422);
        $this->postJson('/api/v1/inspections', $this->inspection(['scheduledDate' => 'next week']))->assertStatus(422);
    }

    public function test_an_inspection_is_filled_in_area_by_area_and_completed(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/inspections', $this->inspection(['type' => 'move-out']))->json('data.id');

        $this->patchJson("/api/v1/inspections/{$id}", [
            'status' => 'completed', 'completedDate' => '2026-11-20', 'depositDeductions' => 45_000, 'notes' => 'Wall marks',
            'items' => [['area' => 'Kitchen', 'condition' => 'good'], ['area' => 'Living room', 'condition' => 'damaged', 'notes' => 'Wall marks']],
        ])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.depositDeductions', 45_000)->assertJsonPath('data.items.1.condition', 'damaged');

        $this->assertSame('2026-11-20', Inspection::query()->find($id)->completedDate);
    }

    public function test_inspection_findings_must_be_well_formed(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/inspections', $this->inspection())->json('data.id');
        $this->as($this->w->managerA);

        $this->patchJson("/api/v1/inspections/{$id}", ['items' => [['condition' => 'good']]])->assertStatus(422);
        $this->patchJson("/api/v1/inspections/{$id}", ['items' => [['area' => 'Kitchen']]])->assertStatus(422);
        $this->patchJson("/api/v1/inspections/{$id}", ['items' => [['area' => 'Kitchen', 'condition' => 'wrecked']]])->assertStatus(422);
        $this->patchJson("/api/v1/inspections/{$id}", ['depositDeductions' => -1])->assertStatus(422);
    }

    public function test_an_inspection_can_be_cancelled(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/inspections', $this->inspection())->json('data.id');

        $this->patchJson("/api/v1/inspections/{$id}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_the_inspector_must_be_on_the_property(): void
    {
        $outsider = World::user('maintenance', 'tech.b@pms.test');
        World::member($this->w->pB, $outsider, 'maintenance');
        $this->as($this->w->managerA);

        $this->postJson('/api/v1/inspections', $this->inspection(['inspectorId' => $outsider->id]))->assertStatus(422);
        $this->postJson('/api/v1/inspections', $this->inspection(['inspectorId' => $this->tech->id]))->assertCreated();
    }

    public function test_who_may_schedule_and_change_inspections(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/inspections', $this->inspection())->json('data.id');

        $this->as($this->tech)->patchJson("/api/v1/inspections/{$id}", ['notes' => 'Visited'])->assertOk();         // the inspector can fill it in
        foreach (['ownerA', 'financeA', 'tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/inspections', $this->inspection())->assertForbidden();
        }
        $this->assertContains($this->as($this->w->ownerB)->patchJson("/api/v1/inspections/{$id}", ['notes' => 'x'])->status(), [403, 404]);
    }

    public function test_inspections_are_scoped_by_property_and_not_shown_to_tenants(): void
    {
        $mine = $this->as($this->w->managerA)->postJson('/api/v1/inspections', $this->inspection())->json('data.id');
        $general = $this->as($this->w->managerA)->postJson('/api/v1/inspections', $this->inspection(['tenantId' => null, 'type' => 'routine']))->json('data.id');
        $theirs = Inspection::query()->create(['id' => 'insp_b', 'code' => 'INS-B', 'propertyId' => 'prop_b', 'unitId' => 'unit_b', 'type' => 'routine', 'status' => 'scheduled', 'scheduledDate' => '2026-11-20']);

        $ids = fn (User $u) => collect($this->as($u)->getJson('/api/v1/inspections?per_page=100')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$mine, $general], $ids($this->w->managerA));
        $this->assertSame([$theirs->id], $ids($this->w->ownerB));
        foreach (['tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who})->getJson('/api/v1/inspections')->assertForbidden();
        }
    }

    public function test_inspections_can_be_filtered_by_type_and_status(): void
    {
        $a = $this->as($this->w->managerA)->postJson('/api/v1/inspections', $this->inspection(['type' => 'routine']))->json('data.id');
        $b = $this->as($this->w->managerA)->postJson('/api/v1/inspections', $this->inspection(['type' => 'move-out', 'status' => 'completed']))->json('data.id');

        $ids = fn (string $q) => collect($this->as($this->w->managerA)->getJson("/api/v1/inspections?per_page=100{$q}")->json('data'))->pluck('id')->all();

        $this->assertSame([$a], $ids('&type=routine'));
        $this->assertSame([$b], $ids('&status=completed'));
    }

    // ======================= Calendar events ===================================

    private function event(array $over = []): array
    {
        return $over + ['title' => 'Viewing at Alpha Court', 'date' => '2026-11-22', 'kind' => 'viewing'];
    }

    public function test_managers_and_admins_add_calendar_events_and_staff_can_see_them(): void
    {
        $id = $this->as($this->w->managerA)->postJson('/api/v1/events', $this->event(['refId' => 'lead_1']))->assertCreated()->assertJsonPath('data.refId', 'lead_1')->json('data.id');
        $this->as($this->w->admin)->postJson('/api/v1/events', $this->event())->assertCreated();

        foreach (['managerA', 'ownerA', 'financeA', 'admin'] as $who) {
            $ids = collect($this->as($this->w->{$who})->getJson('/api/v1/events?per_page=100')->assertOk()->json('data'))->pluck('id')->all();
            $this->assertContains($id, $ids, $who);
        }
    }

    public function test_an_event_needs_a_title_a_date_and_a_known_kind(): void
    {
        $this->as($this->w->managerA);

        foreach (['title', 'date', 'kind'] as $field) {
            $payload = $this->event();
            unset($payload[$field]);
            $this->postJson('/api/v1/events', $payload)->assertStatus(422);
        }
        $this->postJson('/api/v1/events', $this->event(['kind' => 'party']))->assertStatus(422);
        $this->postJson('/api/v1/events', $this->event(['date' => 'soon']))->assertStatus(422);
    }

    public function test_only_managers_and_admins_may_add_events_and_tenants_do_not_see_the_calendar(): void
    {
        foreach (['ownerA', 'financeA', 'tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who})->postJson('/api/v1/events', $this->event())->assertForbidden();
        }
        foreach (['tenantUser', 'stranger'] as $who) {
            $this->as($this->w->{$who})->getJson('/api/v1/events')->assertForbidden();
        }
    }

    public function test_events_can_be_filtered_by_kind_and_reference_and_come_back_in_a_stable_order(): void
    {
        CalendarEvent::query()->create(['id' => 'evt_1', 'title' => 'Viewing', 'date' => '2026-11-22', 'kind' => 'viewing', 'refId' => 'lead_1']);
        CalendarEvent::query()->create(['id' => 'evt_2', 'title' => 'Inspection', 'date' => '2026-11-23', 'kind' => 'inspection', 'refId' => 'insp_1']);

        $ids = fn (string $q) => collect($this->as($this->w->managerA)->getJson("/api/v1/events?per_page=100{$q}")->json('data'))->pluck('id')->all();

        $this->assertSame(['evt_2'], $ids('&kind=inspection'));
        $this->assertSame(['evt_1'], $ids('&refId=lead_1'));
        $this->assertSame(['evt_1'], $ids('&search=viewing'));
        $this->assertSame(['evt_1', 'evt_2'], $ids(''));
    }
}

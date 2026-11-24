<?php

namespace Tests\Feature\Modules;

use App\Models\Setting;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\PmsTestCase;

/**
 * Two superadmins switching different modules at the same moment must not undo each other. A real race cannot be
 * reproduced in one process, but what makes it harmful can: the second writer holding a list that was already out of date.
 */
class ModulesLockTest extends PmsTestCase
{
    private function stored(): array
    {
        return Setting::query()->find('modules')?->value['disabled'] ?? [];
    }

    public function test_a_switch_builds_on_what_another_writer_stored_after_this_one_read_the_list(): void
    {
        $slow = new Modules;
        $slow->disabled();                                            // read the list: nothing is off

        (new Modules)->set('planner', false);                         // somebody else switches Planner off meanwhile
        $slow->set('utilities', false);                               // and only now does this writer switch Utilities off

        $this->assertSame(['utilities', 'planner'], $this->stored(), 'Planner must stay off');
    }

    public function test_switching_on_does_not_bring_back_a_module_another_writer_switched_off_meanwhile(): void
    {
        (new Modules)->set('planner', false);
        $slow = new Modules;
        $slow->disabled();                                            // sees Planner off

        (new Modules)->set('utilities', false);
        $changed = $slow->set('planner', true);

        $this->assertSame(['planner'], $changed);
        $this->assertSame(['utilities'], $this->stored(), 'Utilities must stay off');
    }

    public function test_a_switch_that_finds_the_module_already_in_that_state_changes_nothing(): void
    {
        $slow = new Modules;
        $slow->disabled();
        (new Modules)->set('planner', false);                         // somebody else already did it

        $changed = $slow->set('planner', false);

        $this->assertSame([], $changed);
        $this->assertSame(['planner'], $this->stored());
    }

    public function test_the_dependency_rules_are_judged_on_the_current_list_not_a_stale_one(): void
    {
        $slow = new Modules;
        $slow->disabled();                                            // Maintenance looks on
        (new Modules)->set('maintenance', false, cascade: true);      // but it has just been switched off

        $this->expectException(ValidationException::class);           // so Payouts cannot be switched on over it
        $slow->set('payouts', true);
    }

    public function test_the_instance_reads_the_stored_list_afresh_after_its_own_switch(): void
    {
        $modules = new Modules;
        $this->assertTrue($modules->enabled('planner'));

        $modules->set('planner', false);
        $this->assertFalse($modules->enabled('planner'));

        (new Modules)->set('planner', true);
        $modules->set('utilities', false);
        $this->assertTrue($modules->enabled('planner'), 'it sees the other writer\'s change, not a remembered list');
    }

    public function test_the_very_first_switch_creates_the_row_and_a_second_one_reuses_it(): void
    {
        $this->assertNull(Setting::query()->find('modules'));

        (new Modules)->set('planner', false);
        (new Modules)->set('reports', false);

        $this->assertSame(1, Setting::query()->where('key', 'modules')->count());
        $this->assertSame(['planner', 'reports'], $this->stored());
    }

    public function test_a_refused_switch_leaves_nothing_behind(): void
    {
        try {
            (new Modules)->set('maintenance', false);                // Payouts needs it, and no cascade was asked for
            $this->fail('expected a refusal');
        } catch (ValidationException) {
            // expected
        }

        $this->assertNull(Setting::query()->find('modules'), 'not even the empty row');
        $this->assertTrue((new Modules)->enabled('maintenance'));
    }

    public function test_the_stored_list_keeps_registry_order_whatever_order_the_switches_came_in(): void
    {
        (new Modules)->set('reports', false);
        (new Modules)->set('utilities', false);
        (new Modules)->set('planner', false);

        $this->assertSame(['utilities', 'planner', 'reports'], (new Modules)->disabled());
    }

    public function test_the_list_is_read_under_a_row_lock(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite has no row locks (it lets one writer in at a time); this is checked on Postgres.');
        }

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = strtolower($q->sql);
        });

        (new Modules)->set('planner', false);

        $this->assertNotEmpty(array_filter($queries, fn ($sql) => str_contains($sql, '"settings"') && str_contains($sql, 'for update')), 'the settings row must be selected FOR UPDATE');
    }
}

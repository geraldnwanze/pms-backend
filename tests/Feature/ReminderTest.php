<?php

namespace Tests\Feature;

use App\Mail\RentReminderMail;
use App\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use Tests\PmsTestCase;

class ReminderTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 08:00:00');
    }

    public function test_overdue_reminders_count_the_days_late_as_a_positive_number(): void
    {
        $this->w->invoice->update(['dueDate' => '2026-11-06']);

        $this->as($this->w->managerA)->postJson('/api/v1/reminders/overdue')->assertOk();

        $body = Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Rent overdue')->value('body');
        $this->assertStringContainsString('5 day(s) overdue', $body);
        $this->assertStringNotContainsString('-', explode('₦', $body)[1] ?? '');
        Mail::assertQueued(RentReminderMail::class, fn (RentReminderMail $m) => $m->hasTo('ada@tenant.test'));
    }

    public function test_an_overdue_reminder_links_straight_to_that_invoice(): void
    {
        $this->w->invoice->update(['dueDate' => '2026-11-06']);

        $this->as($this->w->managerA)->postJson('/api/v1/reminders/overdue')->assertOk();

        $id = $this->w->invoice->id;
        $this->assertSame("/portal/bills/{$id}", Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Rent overdue')->value('link'));
        Mail::assertQueued(RentReminderMail::class, function (RentReminderMail $m) use ($id) {
            $m->assertSeeInHtml(config('app.frontend_url')."/portal/bills/{$id}");

            return true;
        });
    }

    public function test_the_daily_command_links_due_soon_and_overdue_reminders_to_the_invoice(): void
    {
        $this->w->invoice->update(['dueDate' => '2026-11-14']);                                       // due in 3 days
        $late = $this->w->invoice(['id' => 'inv_late', 'dueDate' => '2026-11-01']);

        $this->artisan('pms:rent-reminders')->assertSuccessful();

        $links = Notification::query()->where('forUserId', 'ten_ada')->whereIn('title', ['Rent due soon', 'Rent overdue'])->pluck('link')->all();
        $this->assertEqualsCanonicalizing(["/portal/bills/{$late->id}", "/portal/bills/{$this->w->invoice->id}"], $links);
        foreach ([$late->id, $this->w->invoice->id] as $id) {
            Mail::assertQueued(RentReminderMail::class, function (RentReminderMail $m) use ($id) {
                try {
                    $m->assertSeeInHtml(config('app.frontend_url')."/portal/bills/{$id}");

                    return true;
                } catch (AssertionFailedError) {
                    return false;
                }
            });
        }
    }

    public function test_only_overdue_unpaid_invoices_are_chased(): void
    {
        $this->w->invoice->update(['dueDate' => '2026-11-11']);                       // due today: not overdue
        $this->w->invoice(['id' => 'inv_paid', 'dueDate' => '2026-10-01', 'amountPaid' => 100_000, 'status' => 'fully-paid']);
        $this->w->invoice(['id' => 'inv_late', 'dueDate' => '2026-10-01']);

        $this->as($this->w->managerA)->postJson('/api/v1/reminders/overdue')->assertOk()->assertJsonPath('data.invoices', 1);
    }

    public function test_expiring_lease_reminders_count_down_the_days_left(): void
    {
        $this->w->lease->update(['endDate' => '2026-11-25']);

        $this->as($this->w->managerA)->postJson('/api/v1/reminders/expiring-leases', ['days' => 30])->assertOk();

        $body = Notification::query()->where('forUserId', 'ten_ada')->where('title', 'Lease expiring soon')->value('body');
        $this->assertStringContainsString('ends in 14 day(s)', $body);
    }

    public function test_a_lease_outside_the_window_is_not_reminded(): void
    {
        $this->w->lease->update(['endDate' => '2027-06-01']);

        $this->as($this->w->managerA)->postJson('/api/v1/reminders/expiring-leases', ['days' => 30])->assertOk()->assertJsonPath('data.leases', 0);
    }

    public function test_reminders_are_scoped_to_the_callers_properties(): void
    {
        $this->w->invoice->update(['dueDate' => '2026-11-01']);

        $this->as($this->w->ownerB)->postJson('/api/v1/reminders/overdue')->assertOk()->assertJsonPath('data.invoices', 0);
        $this->assertSame(0, Notification::query()->where('title', 'Rent overdue')->count());
    }

    public function test_tenants_cannot_trigger_reminders(): void
    {
        $this->as($this->w->tenantUser)->postJson('/api/v1/reminders/overdue')->assertForbidden();
        $this->as($this->w->tenantUser)->postJson('/api/v1/reminders/expiring-leases')->assertForbidden();
    }
}

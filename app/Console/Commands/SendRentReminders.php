<?php

namespace App\Console\Commands;

use App\Console\Concerns\SkipsWhenModuleOff;
use App\Mail\RentReminderMail;
use App\Models\Invoice;
use App\Services\NotificationService;
use App\Support\FrontendUrl;
use App\Support\Recipients;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Queues a rent reminder to each tenant with an unpaid invoice that is overdue
 * or due within the next week. Idempotent enough for a daily schedule: a tenant
 * gets one nudge per run while the invoice stays outstanding.
 */
class SendRentReminders extends Command
{
    use SkipsWhenModuleOff;

    protected $signature = 'pms:rent-reminders {--days=7 : Look-ahead window for "due soon"}';

    protected $description = 'Send rent due-soon / overdue reminders to tenants (in-app + queued email)';

    public function handle(NotificationService $notifications): int
    {
        if ($this->moduleIsOff('reminders')) {
            return self::SUCCESS;
        }

        $window = (int) $this->option('days');
        $today = Carbon::now()->startOfDay();
        $stamp = $today->format('Ymd');
        $cutoff = $today->copy()->addDays($window);

        $invoices = Invoice::query()
            ->whereNotIn('status', ['cancelled', 'fully-paid'])
            ->whereColumn('amountPaid', '<', 'total')
            ->whereNotNull('dueDate')
            ->get(['id', 'code', 'tenantId', 'dueDate', 'total', 'amountPaid']);

        $notified = 0;
        $emailed = 0;

        foreach ($invoices as $inv) {
            $outstanding = max(0, (int) $inv->total - (int) $inv->amountPaid);
            if ($outstanding <= 0) {
                continue;
            }

            $due = Carbon::parse($inv->dueDate)->startOfDay();
            if ($due->greaterThan($cutoff)) {
                continue; // not due for a while yet
            }

            $overdue = $due->lessThan($today);
            $days = (int) abs($today->diffInDays($due));

            // In-app: keyed per invoice per day, so re-runs update rather than pile up.
            $notifications->push([
                'id' => "ntf_rent_{$inv->id}_{$stamp}",
                'kind' => 'rent',
                'title' => $overdue ? 'Rent overdue' : 'Rent due soon',
                'body' => $overdue
                    ? "Invoice {$inv->code} for ₦".number_format($outstanding)." was due {$days} day(s) ago."
                    : "Invoice {$inv->code} for ₦".number_format($outstanding).' is due '.($days === 0 ? 'today' : "in {$days} day(s)").'.',
                'forUserId' => $inv->tenantId,
                'link' => "/portal/bills/{$inv->id}",
            ]);
            $notified++;

            $recipients = Recipients::tenantUsers($inv->tenantId);
            if ($recipients->isNotEmpty()) {
                Mail::to($recipients->all())->queue(new RentReminderMail([
                    'code' => $inv->code,
                    'dueDate' => $due->toFormattedDateString(),
                    'outstanding' => '₦'.number_format($outstanding),
                    'overdue' => $overdue,
                    'days' => $days,
                    'portalUrl' => FrontendUrl::to("/portal/bills/{$inv->id}"),
                ]));
                $emailed++;
            }
        }

        $this->info("Rent reminders: {$notified} in-app, {$emailed} email(s) queued.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Services;

use App\Mail\RentReminderMail;
use App\Models\Invoice;
use App\Support\FrontendUrl;
use App\Support\Recipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * On-demand payment reminders (the scheduled `pms:rent-reminders` command
 * covers the automated daily sweep).
 */
class ReminderService
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Nudge every tenant with an overdue balance, within the given property
     * scope. Queues an email where the tenant has a login, and always drops an
     * in-app notification (keyed per invoice per day, so repeat clicks the same
     * day don't pile up).
     *
     * @param  array<int,string>|null  $propertyIds  null = no restriction (admin)
     * @return array{invoices:int,notified:int,emailed:int}
     */
    public function sendOverdue(?array $propertyIds, ?string $onlyProperty = null): array
    {
        $today = Carbon::now()->startOfDay();
        $stamp = $today->format('Ymd');

        $invoices = Invoice::query()
            ->whereNotIn('status', ['cancelled', 'fully-paid'])
            ->whereColumn('amountPaid', '<', 'total')
            ->whereNotNull('dueDate')
            ->when($onlyProperty !== null, fn ($q) => $q->where('propertyId', $onlyProperty))
            ->when($onlyProperty === null && $propertyIds !== null,
                fn ($q) => $q->whereIn('propertyId', $propertyIds ?: ['__none__']))
            ->get(['id', 'code', 'tenantId', 'dueDate', 'total', 'amountPaid']);

        $count = 0;
        $notified = 0;
        $emailed = 0;

        foreach ($invoices as $inv) {
            $outstanding = max(0, (int) $inv->total - (int) $inv->amountPaid);
            if ($outstanding <= 0) {
                continue;
            }

            $due = Carbon::parse($inv->dueDate)->startOfDay();
            if (! $due->lessThan($today)) {
                continue; // overdue only
            }

            $count++;
            $days = (int) $today->diffInDays($due);

            $this->notifications->push([
                'id' => "ntf_rent_{$inv->id}_{$stamp}",
                'kind' => 'rent',
                'title' => 'Rent overdue',
                'body' => "Invoice {$inv->code} for ₦".number_format($outstanding)." is {$days} day(s) overdue. Please settle it.",
                'forUserId' => $inv->tenantId,
                'link' => '/portal/pay',
            ]);
            $notified++;

            $recipients = Recipients::tenantUsers($inv->tenantId);
            if ($recipients->isNotEmpty()) {
                Mail::to($recipients->all())->queue(new RentReminderMail([
                    'code' => $inv->code,
                    'dueDate' => $due->toFormattedDateString(),
                    'outstanding' => '₦'.number_format($outstanding),
                    'overdue' => true,
                    'days' => $days,
                    'portalUrl' => FrontendUrl::to('/portal/pay'),
                ]));
                $emailed++;
            }
        }

        return ['invoices' => $count, 'notified' => $notified, 'emailed' => $emailed];
    }
}

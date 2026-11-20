<?php

namespace App\Services;

use App\Mail\LeaseExpiringMail;
use App\Mail\RentReminderMail;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Support\FrontendUrl;
use App\Support\Recipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * On-demand payment and lease-renewal reminders (the scheduled
 * `pms:rent-reminders` / `pms:lease-expiry-reminders` commands cover the
 * automated daily sweeps and share this same logic).
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
            $days = (int) $due->diffInDays($today); // $due is in the past: Carbon 3 diffs are signed, so count forwards from it

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

    /**
     * Nudge every tenant whose active lease ends within the next N days, so
     * they (and, via the in-app notification, staff) have time to start
     * renewal talks. Queues an email where the tenant has a login, and always
     * drops an in-app notification (keyed per lease per day).
     *
     * @param  array<int,string>|null  $propertyIds  null = no restriction (admin)
     * @return array{leases:int,notified:int,emailed:int}
     */
    public function sendExpiringLeases(?array $propertyIds, ?string $onlyProperty = null, int $days = 60): array
    {
        $today = Carbon::now()->startOfDay();
        $stamp = $today->format('Ymd');
        $cutoff = $today->copy()->addDays($days);

        $leases = Lease::query()
            ->whereIn('status', ['active', 'expiring'])
            ->whereNotNull('endDate')
            ->when($onlyProperty !== null, fn ($q) => $q->where('propertyId', $onlyProperty))
            ->when($onlyProperty === null && $propertyIds !== null,
                fn ($q) => $q->whereIn('propertyId', $propertyIds ?: ['__none__']))
            ->get(['id', 'code', 'tenantId', 'propertyId', 'unitId', 'endDate', 'status']);

        $count = 0;
        $notified = 0;
        $emailed = 0;

        foreach ($leases as $lease) {
            $end = Carbon::parse($lease->endDate)->startOfDay();
            if ($end->lessThan($today) || $end->greaterThan($cutoff)) {
                continue;
            }

            $count++;
            $daysLeft = (int) $today->diffInDays($end);

            $this->notifications->push([
                'id' => "ntf_lease_{$lease->id}_{$stamp}",
                'kind' => 'lease',
                'title' => 'Lease expiring soon',
                'body' => "Lease {$lease->code} ends in {$daysLeft} day(s) ({$end->toFormattedDateString()}).",
                'forUserId' => $lease->tenantId,
                'link' => '/portal/lease',
            ]);
            $notified++;

            $recipients = Recipients::tenantUsers($lease->tenantId);
            if ($recipients->isNotEmpty()) {
                Mail::to($recipients->all())->queue(new LeaseExpiringMail([
                    'code' => $lease->code,
                    'endDate' => $end->toFormattedDateString(),
                    'daysLeft' => $daysLeft,
                    'propertyName' => Property::query()->whereKey($lease->propertyId)->value('name') ?? '—',
                    'unitCode' => $lease->unitId ? Unit::query()->whereKey($lease->unitId)->value('code') : null,
                    'portalUrl' => FrontendUrl::to('/portal/lease'),
                ]));
                $emailed++;
            }
        }

        return ['leases' => $count, 'notified' => $notified, 'emailed' => $emailed];
    }
}

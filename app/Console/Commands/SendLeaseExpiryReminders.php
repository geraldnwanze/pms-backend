<?php

namespace App\Console\Commands;

use App\Mail\LeaseExpiringMail;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Services\NotificationService;
use App\Support\FrontendUrl;
use App\Support\Recipients;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Queues a lease-expiry reminder to each tenant whose active lease ends within
 * the next N days (default 60).
 */
class SendLeaseExpiryReminders extends Command
{
    protected $signature = 'pms:lease-expiry-reminders {--days=60 : How many days ahead to warn}';

    protected $description = 'Send lease-expiring reminders to tenants (in-app + queued email)';

    public function handle(NotificationService $notifications): int
    {
        $days = (int) $this->option('days');
        $today = Carbon::now()->startOfDay();
        $stamp = $today->format('Ymd');
        $cutoff = $today->copy()->addDays($days);

        $leases = Lease::query()
            ->whereIn('status', ['active', 'expiring'])
            ->whereNotNull('endDate')
            ->get(['id', 'code', 'tenantId', 'propertyId', 'unitId', 'endDate', 'status']);

        $notified = 0;
        $emailed = 0;

        foreach ($leases as $lease) {
            $end = Carbon::parse($lease->endDate)->startOfDay();
            if ($end->lessThan($today) || $end->greaterThan($cutoff)) {
                continue;
            }

            $daysLeft = (int) $today->diffInDays($end);

            $notifications->push([
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

        $this->info("Lease-expiry reminders: {$notified} in-app, {$emailed} email(s) queued.");

        return self::SUCCESS;
    }
}

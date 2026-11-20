<?php

namespace App\Models;

use Illuminate\Support\Carbon;

class Lease extends PmsModel
{
    protected $table = 'leases';

    protected $casts = [
        'rent' => 'integer',
        'securityDeposit' => 'integer',
        'noticePeriodDays' => 'integer',
        'signed' => 'boolean',
        'depositLedger' => 'array',
    ];

    /** Statuses of a lease that is running (see LeaseService::CURRENT). */
    private const RUNNING = ['active', 'expiring', 'renewed'];

    /** Whether this lease holds its unit on that day: running, and the day falls within its dates. */
    public function holdsUnitOn(?Carbon $day = null): bool
    {
        $d = ($day ?? Carbon::today())->toDateString();

        return in_array($this->status, self::RUNNING, true) && $this->startDate <= $d && $this->endDate >= $d;
    }

    /**
     * The day the tenant's tenancy ended, as of $today, or null if it has not (or if we cannot tell). A terminated lease ended
     * the day it was terminated — its `endDate` is still the contract's, usually in the future — and an older one with no such
     * day only counts if its end date has passed. An expired one ended on its end date, and so did a running one whose end
     * date has gone by before the daily status job caught up.
     */
    public function endedOn(?Carbon $today = null): ?string
    {
        $now = ($today ?? Carbon::today())->toDateString();

        return match (true) {
            $this->status === 'terminated' => $this->terminatedOn ?: ($this->endDate && $this->endDate <= $now ? $this->endDate : null),
            $this->status === 'expired' => $this->endDate ?: null,
            in_array($this->status, self::RUNNING, true) => $this->endDate && $this->endDate < $now ? $this->endDate : null,
            default => null,
        };
    }
}

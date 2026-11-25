<?php

namespace App\Jobs;

use App\Support\Heartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Dispatched every minute by the scheduler. When a worker picks it up it records the time, so `pms:status` can tell
 * whether jobs are actually being worked — not just whether they are piling up.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    /** A beat that waits longer than this is worthless: the next one is already on its way. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(2);
    }

    public function handle(): void
    {
        Heartbeat::beat(Heartbeat::WORKER);
    }
}

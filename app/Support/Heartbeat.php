<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The last time the scheduler ticked and the last time a queue worker ran a job, kept in the cache.
 * Both are written every minute, so a gap of a few minutes means that process is not running.
 */
final class Heartbeat
{
    public const SCHEDULER = 'pms:heartbeat:scheduler';

    public const WORKER = 'pms:heartbeat:worker';

    /** Minutes without a beat after which the process is reported as down. */
    public const STALE_AFTER_MINUTES = 3;

    public static function beat(string $key): void
    {
        Cache::forever($key, now()->toIso8601String());
    }

    public static function last(string $key): ?Carbon
    {
        $value = Cache::get($key);

        return $value ? Carbon::parse($value) : null;
    }

    public static function isAlive(string $key): bool
    {
        $last = self::last($key);

        return $last !== null && $last->gte(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}

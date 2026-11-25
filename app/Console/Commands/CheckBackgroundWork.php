<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Runs from the scheduler every few minutes and tells the superadmins, in the app, when the queue worker has stopped
 * or jobs have started failing. (The scheduler cannot report on itself: for that, point an uptime monitor at
 * `GET /api/health/background`, which answers 503 when either process has gone quiet.)
 *
 * Each problem is raised once, not every run: the worker alert is raised when it stops and cleared when it is back;
 * the failed-jobs alert is raised again only when the number of failed jobs has grown.
 */
class CheckBackgroundWork extends Command
{
    private const WORKER_ALERTED = 'pms:alert:worker-down';

    private const FAILED_SEEN = 'pms:alert:failed-jobs-seen';

    protected $signature = 'pms:check-background';

    protected $description = 'Tell the superadmins in the app if the queue worker has stopped or jobs are failing';

    public function handle(NotificationService $notifications): int
    {
        $this->checkWorker($notifications);
        $this->checkFailedJobs($notifications);

        return self::SUCCESS;
    }

    private function checkWorker(NotificationService $notifications): void
    {
        $alive = Heartbeat::isAlive(Heartbeat::WORKER);
        $alerted = Cache::has(self::WORKER_ALERTED);

        if (! $alive && ! $alerted) {
            Cache::forever(self::WORKER_ALERTED, now()->toIso8601String());
            $notifications->push([
                'kind' => 'system',
                'title' => 'The queue worker has stopped',
                'body' => 'Emails and push notifications are not being sent. Start `php artisan queue:work`, or check `php artisan pms:status`.',
                'forRole' => 'superadmin',
            ]);
            $this->warn('Queue worker is down: alert raised.');
        } elseif ($alive && $alerted) {
            Cache::forget(self::WORKER_ALERTED);
            $notifications->push([
                'kind' => 'system',
                'title' => 'The queue worker is back',
                'body' => 'Queued emails and push notifications are being sent again.',
                'forRole' => 'superadmin',
            ]);
            $this->info('Queue worker is back: alert cleared.');
        }
    }

    private function checkFailedJobs(NotificationService $notifications): void
    {
        $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
        $seen = (int) Cache::get(self::FAILED_SEEN, 0);

        // Remember the count either way, so clearing the failures (queue:flush, pruning) re-arms the alert.
        Cache::forever(self::FAILED_SEEN, $failed);

        if ($failed > $seen) {
            $notifications->push([
                'kind' => 'system',
                'title' => $failed === 1 ? 'A background job failed' : "{$failed} background jobs have failed",
                'body' => 'See `php artisan queue:failed`; `php artisan queue:retry all` runs them again.',
                'forRole' => 'superadmin',
            ]);
            $this->warn("{$failed} failed job(s): alert raised.");
        }
    }
}

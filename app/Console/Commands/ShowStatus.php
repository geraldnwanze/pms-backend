<?php

namespace App\Console\Commands;

use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ShowStatus extends Command
{
    protected $signature = 'pms:status';

    protected $description = 'Show whether the scheduler and the queue worker are running, and what is waiting or has failed. Exits 1 if either is down.';

    public function handle(): int
    {
        $rows = [];
        $healthy = true;

        foreach (['Scheduler' => Heartbeat::SCHEDULER, 'Queue worker' => Heartbeat::WORKER] as $label => $key) {
            $last = Heartbeat::last($key);
            $alive = Heartbeat::isAlive($key);
            $healthy = $healthy && $alive;
            $rows[] = [$label, $alive ? 'running' : 'DOWN', $last ? $last->diffForHumans() : 'never'];
        }

        $this->table(['Process', 'State', 'Last beat'], $rows);

        $this->line('Waiting jobs: '.DB::table(config('queue.connections.database.table', 'jobs'))->count());
        $this->line('Failed jobs:  '.DB::table(config('queue.failed.table', 'failed_jobs'))->count());

        if (! $healthy) {
            $this->error('A background process is not running. Start it with `php artisan dev`, or see the README.');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}

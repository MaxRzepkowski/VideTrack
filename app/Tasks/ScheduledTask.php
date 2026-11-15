<?php

namespace App\Tasks;

use App\Models\TaskRun;
use Closure;
use Cron\CronExpression;
use Illuminate\Support\Carbon;

/**
 * One recurring background task: how often it runs (cron),
 * a translatable frequency label for the settings "Tasks" section and
 * the closure that queues the job with its `task_runs` record.
 */
class ScheduledTask
{
    public function __construct(
        public readonly TaskName $name,
        public readonly string $cron,
        public readonly string $frequency,
        public readonly Closure $queue,
    ) {}

    /**
     * Queues a run now, unless one is already queued or in progress — an
     * operator who does not want to wait for the next cron window. Answers
     * whether a run was queued.
     */
    public function runNow(): bool
    {
        if (TaskRun::query()->inFlight($this->name)->exists()) {
            return false;
        }

        ($this->queue)();

        return true;
    }

    public function nextRunAt(): Carbon
    {
        return Carbon::instance((new CronExpression($this->cron))->getNextRunDate(Carbon::now()));
    }

    /**
     * The most recent time this task was due to fire on or before $now — the
     * boundary catch-up compares against to spot a missed run. Never counts
     * the current instant, so at the exact scheduled minute the real cron owns
     * the run and catch-up stays out of its way.
     */
    public function previousRunAt(?Carbon $now = null): Carbon
    {
        return Carbon::instance(
            (new CronExpression($this->cron))->getPreviousRunDate($now ?? Carbon::now())
        );
    }
}

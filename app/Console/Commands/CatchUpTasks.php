<?php

namespace App\Console\Commands;

use App\Models\TaskRun;
use App\Tasks\Maintenance\MaintenanceChecks;
use App\Tasks\ScheduledTasks;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recovers missed recurring runs. Laravel's scheduler only fires
 * a task when the process is alive at the exact cron minute and never makes up
 * a run it slept through — so on a host that is not 24/7 (a laptop off at
 * night, a container started after 04:00) a "daily" refresh silently skips.
 *
 * For each task this compares the newest `task_runs.queued_at` against the most
 * recent time the cron was due: if nothing ran in that window the task is
 * queued once. Overdue by six windows still means a SINGLE fresh run — we run
 * the latest occurrence, never replay a backlog. The scheduler runs this every
 * half hour and the container entrypoint runs it once on boot, so a late start
 * catches up instead of waiting for the next window.
 */
class CatchUpTasks extends Command
{
    protected $signature = 'tasks:catch-up';

    protected $description = 'Queue recurring tasks whose last run predates their most recent due time (recovers runs missed during downtime).';

    public function handle(MaintenanceChecks $checks): int
    {
        $now = Carbon::now();

        foreach (ScheduledTasks::all() as $task) {
            $due = $task->previousRunAt($now);

            $lastQueued = TaskRun::query()
                ->where('name', $task->name->value)
                ->max('queued_at');

            // Already ran (or is running) in the current window — leave it to
            // the regular scheduler.
            if (is_string($lastQueued) && Carbon::parse($lastQueued)->gte($due)) {
                continue;
            }

            ($task->queue)();

            $this->info("Caught up overdue task [{$task->name->value}] (due {$due->toDateTimeString()}).");
        }

        // The maintenance windows keep no task runs: their checks' own rows
        // say when each last ran.
        foreach ($checks->overdueWindows($now) as $window) {
            $checks->queueWindow($window);

            $this->info("Caught up overdue maintenance window [{$window->value}].");
        }

        return self::SUCCESS;
    }
}

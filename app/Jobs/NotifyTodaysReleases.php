<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsAsTask;
use App\Models\TaskRun;
use App\Models\User;
use App\Tasks\TaskName;
use App\Tracking\Surfaces\TodaysReleases;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The daily date-reached events: today's releases of every user's tracked
 * titles, announced by {@see TodaysReleases}.
 */
class NotifyTodaysReleases implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    // The worker's default, stated: the overlap lock lapses with it.
    public int $timeout = 60;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    public function __construct(private readonly TaskRun $taskRun) {}

    /** Creates the pending task_runs record and queues the job. */
    public static function queueTask(): void
    {
        self::dispatch(TaskRun::queueTask(TaskName::NotifyReleases->value));
    }

    public function handle(TodaysReleases $releases): void
    {
        $this->taskRun->markStarted();

        $today = today()->toDateString();
        $created = 0;
        $users = 0;

        foreach (User::query()->get() as $user) {
            if (! $releases->wantedBy($user)) {
                continue;
            }

            $created += $releases->announce($user, $today);
            $users++;
        }

        $this->taskRun->markSuccess(__('tasks.summary_release_notifications', ['created' => $created, 'users' => $users]));
    }
}

<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsAsTask;
use App\Library\LibraryProjector;
use App\Models\TaskRun;
use App\Tasks\TaskName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Each day the titles with an episode out in the last days are worked out
 * again ({@see LibraryProjector::projectReleasedSince()}); a title whose
 * count did not move writes nothing.
 */
class ProjectAiredEpisodes implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    /** How far back a release is still picked up: a scheduler asleep for a few days still catches up. */
    public const LOOKBACK_DAYS = 7;

    public int $timeout = 600;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    public function __construct(private readonly TaskRun $taskRun) {}

    public static function queueTask(): void
    {
        self::dispatch(TaskRun::queueTask(TaskName::ProjectAiredEpisodes->value));
    }

    public function handle(LibraryProjector $projector): void
    {
        $this->taskRun->markStarted();

        $projected = $projector->projectReleasedSince(today()->subDays(self::LOOKBACK_DAYS));
        $titles = count(array_unique(array_merge([], ...array_values($projected))));
        $accounts = count($projected);

        $this->taskRun->markSuccess(__('tasks.summary_aired_episodes', ['titles' => $titles, 'accounts' => $accounts]));
    }
}

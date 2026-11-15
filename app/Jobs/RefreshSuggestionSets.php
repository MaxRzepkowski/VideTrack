<?php

namespace App\Jobs;

use App\Actions\Suggestions\FetchWantedSets;
use App\Jobs\Concerns\RunsAsTask;
use App\Models\TaskRun;
use App\Tasks\ProgressReporter;
use App\Tasks\TaskName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copies the provider sets the suggestion shelves read (the parts of the
 * collections users have begun). Reports to `task_runs`.
 */
class RefreshSuggestionSets implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    /** Seconds of asking one run takes on; the rest stays due for the next. */
    public const WORK_SECONDS = 600;

    public int $timeout = 900;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    public function __construct(private readonly TaskRun $taskRun) {}

    /** Creates the pending task_runs record and queues the job. */
    public static function queueTask(): void
    {
        self::dispatch(TaskRun::queueTask(TaskName::RefreshSuggestionSets->value));
    }

    public function handle(FetchWantedSets $fetch): void
    {
        $this->taskRun->markStarted();

        $result = $fetch->execute(now()->addSeconds(self::WORK_SECONDS), new ProgressReporter($this->taskRun));

        $summary = "Fetched {$result->fetched} of {$result->due} sets due.";

        if ($result->gone > 0) {
            $summary .= " Removed {$result->gone} no longer at the provider.";
        }

        if ($result->forgotten > 0) {
            $summary .= " Forgot {$result->forgotten} nobody needs any more.";
        }

        $this->taskRun->markSuccess($summary, $result->errors);
    }
}

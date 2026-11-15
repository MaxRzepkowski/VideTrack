<?php

namespace App\Jobs;

use App\Actions\Media\RefreshDueRelations;
use App\Jobs\Concerns\RunsAsTask;
use App\Models\TaskRun;
use App\Tasks\ProgressReporter;
use App\Tasks\TaskName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks the outside source which titles the stored ones are tied to
 * (spin-offs, what follows, the rest of a franchise) for as long as the run
 * may work. A title is asked about again only after months. Reports to
 * `task_runs`.
 */
class RefreshRelations implements ShouldQueue
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
        self::dispatch(TaskRun::queueTask(TaskName::RefreshRelations->value));
    }

    public function handle(RefreshDueRelations $refresh): void
    {
        $this->taskRun->markStarted();

        $result = $refresh->execute(
            now()->addSeconds(self::WORK_SECONDS),
            config()->string('tracker.default_locale'),
            new ProgressReporter($this->taskRun),
        );

        $this->taskRun->markSuccess(
            "Asked about {$result->asked} of {$result->due} titles due. Kept {$result->ties} ties and {$result->listed} titles of their franchises.",
            $result->errors,
        );
    }
}

<?php

namespace App\Jobs;

use App\Actions\Media\RefreshEvents as RefreshEventsAction;
use App\Jobs\Concerns\RunsAsTask;
use App\Models\TaskRun;
use App\Tasks\TaskName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/** Keeps the events the calendar and the "live" button read. Reports to `task_runs`. */
class RefreshEvents implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    public int $timeout = 300;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    public function __construct(private readonly TaskRun $taskRun) {}

    /** Creates the pending task_runs record and queues the job. */
    public static function queueTask(): void
    {
        self::dispatch(TaskRun::queueTask(TaskName::RefreshEvents->value));
    }

    public function handle(RefreshEventsAction $refresh): void
    {
        $this->taskRun->markStarted();

        $result = $refresh->execute();

        $this->taskRun->markSuccess(
            "Kept {$result['events']} events.",
            array_map(fn (string $provider): string => "{$provider} did not answer.", $result['failed']),
        );
    }
}

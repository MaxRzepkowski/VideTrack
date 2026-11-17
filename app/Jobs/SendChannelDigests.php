<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsAsTask;
use App\Models\TaskRun;
use App\Notifications\Channels\DailyDigests;
use App\Tasks\TaskName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/** The hourly run of the daily digests (DailyDigests), recorded as a task. */
class SendChannelDigests implements ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    /**
     * One HTTP round trip per channel, each allowed several seconds —
     * the worker's 60-second default would let a handful of dead channels
     * kill the run before it reaches everyone else. Stays under the database
     * queue's retry_after (3690s) so a slow run is never picked up twice.
     */
    public int $timeout = 900;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    public function __construct(private readonly TaskRun $taskRun) {}

    /** Creates the pending task_runs record and queues the job. */
    public static function queueTask(): void
    {
        self::dispatch(TaskRun::queueTask(TaskName::ChannelDigest->value));
    }

    public function handle(DailyDigests $digests): void
    {
        $this->taskRun->markStarted();

        $this->taskRun->markSuccess($digests->send()->summary());
    }
}

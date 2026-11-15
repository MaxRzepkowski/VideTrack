<?php

namespace App\Jobs;

use App\Actions\Media\PlanCatalogRefresh;
use App\Jobs\Concerns\HandsOverWork;
use App\Jobs\Concerns\RunsAsTask;
use App\Models\TaskRun;
use App\Tasks\ProgressReporter;
use App\Tasks\TaskName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The daily background refresh of stored titles: plans what to ask about,
 * then hands the asking to {@see RefreshCatalogBatch}, batch by batch, all
 * under one `task_runs` row.
 */
class RefreshCatalog implements HandsOverWork, ShouldQueue
{
    use Dispatchable, Queueable, RunsAsTask;

    /** Planning reads the database only. */
    public int $timeout = 600;

    public int $maxExceptions = self::BREAKS_ALLOWED;

    private ?RefreshCatalogBatch $firstBatch = null;

    public function __construct(private readonly TaskRun $taskRun) {}

    /** Creates the pending task_runs record and queues the job. */
    public static function queueTask(): void
    {
        self::dispatch(TaskRun::queueTask(TaskName::RefreshCatalog->value));
    }

    public function handle(PlanCatalogRefresh $plan): void
    {
        $this->taskRun->markStarted();
        (new ProgressReporter($this->taskRun))->reportNow(__('tasks.progress_planning'));

        $this->firstBatch = new RefreshCatalogBatch($this->taskRun, $plan->execute());
    }

    public function handOver(): ?object
    {
        return $this->firstBatch;
    }
}

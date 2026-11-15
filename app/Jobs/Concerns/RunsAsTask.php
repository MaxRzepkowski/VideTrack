<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\OneRunAtATime;
use App\Models\TaskRun;
use App\Support\ExternalServiceException;
use Throwable;

/**
 * What every job reporting to task_runs needs: one run of a task at a time,
 * and a failure that always closes its row — a job without failed() would
 * leave the row "started" for good. The using job holds its row as
 * `$taskRun` and declares its `$timeout` and its `$maxExceptions` — how many
 * times it may break before the run is given up. The worker's --tries bound
 * the rest (a run killed with its timeout or with the worker); a wait behind
 * a twin spends none of them.
 *
 * @property-read TaskRun $taskRun
 * @property-read int $timeout
 * @property-read int $maxExceptions
 */
trait RunsAsTask
{
    /** A translation key: the run's summary is worded in the language it is read in. */
    public const UNEXPECTED_FAILURE = 'tasks.the_run_stopped_on_an_unexpected_error';

    /** A task's `$maxExceptions` unless it says otherwise: the queue worker's own --tries. */
    public const BREAKS_ALLOWED = 3;

    /** How often a run waiting behind its twin looks again. */
    public const OVERLAP_RECHECK_SECONDS = 60;

    /**
     * A second run of the same task (the scheduler and the catch-up, a manual
     * run, an import queued twice) waits for the first instead of racing it,
     * looking again every minute, however long the first takes — a deadline
     * would also count the time a run sat in the queue behind other tasks,
     * and fail a daily run that never started.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new OneRunAtATime(
            "{$this->taskRun->name}:{$this->taskRun->user_id}",
            $this->timeout,
            min(self::OVERLAP_RECHECK_SECONDS, $this->timeout),
            fn (): static => $this->postponed(),
        )];
    }

    /** This run as a fresh job, to queue again: detached from the entry it came in on. */
    private function postponed(): static
    {
        $copy = clone $this;
        $copy->job = null;

        return $copy;
    }

    /**
     * An outside service's failure is worded by us and safe to show; any
     * other exception may carry a query, a path or an address, so the run
     * says only that it broke — the worker logs the rest.
     */
    public function failed(Throwable $exception): void
    {
        $this->taskRun->markFailureIfOpen($exception instanceof ExternalServiceException
            ? $exception->wording()
            : __(self::UNEXPECTED_FAILURE));
    }
}

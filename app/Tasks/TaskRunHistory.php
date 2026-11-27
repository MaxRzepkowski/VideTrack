<?php

namespace App\Tasks;

use App\Enums\TaskRunStatus;
use App\Integrations\Import\Contracts\ImportSource;
use App\Integrations\Import\ImportSourceRegistry;
use App\Models\TaskRun;
use App\Models\User;
use App\Tasks\Data\TaskRunItem;
use App\Tasks\Maintenance\MaintenanceChecks;
use App\Tasks\Maintenance\MaintenanceRegistry;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * The task runs a viewer sees: their own, plus the system's (user_id null)
 * for an admin. Capped, so a busy instance does not render hundreds of rows.
 */
final readonly class TaskRunHistory
{
    /** Newest runs shown at once — enough for context without flooding the page. */
    public const RUN_LIMIT = 25;

    public function __construct(
        private ImportSourceRegistry $imports,
        private MaintenanceRegistry $checks,
    ) {}

    /**
     * @return list<TaskRunItem>
     */
    public function recent(User $viewer): array
    {
        return array_values($this->visible($viewer)
            ->orderByDesc('queued_at')
            ->limit(self::RUN_LIMIT)
            ->get()
            ->map(fn (TaskRun $run): TaskRunItem => new TaskRunItem(
                id: $run->id,
                name: $run->name,
                label: $this->label($run->name),
                status: $run->status,
                summary: $run->summary,
                errors: $run->errors ?? [],
                details: $run->details ?? [],
                queuedAt: $run->queued_at->toIso8601String(),
                finishedAt: $run->finished_at?->toIso8601String(),
            ))
            ->all());
    }

    /** Whether there is finished history to clear. */
    public function hasFinished(User $viewer): bool
    {
        return $this->finished($viewer)->exists();
    }

    /** Prunes the finished runs; one in flight (an import under way) is never lost. */
    public function clearFinished(User $viewer): void
    {
        $this->finished($viewer)->delete();
    }

    /**
     * A recurring task by its name, an import by its source, a maintenance
     * check by its code — the registries know how each reads, so a new
     * source or check needs no change here.
     */
    private function label(string $name): string
    {
        $task = TaskName::tryFrom($name);

        if ($task !== null) {
            return $task->label();
        }

        foreach ($this->imports->all() as $source) {
            /** @var ImportSource $source */
            if ($name === ImportSourceRegistry::taskName($source->code())) {
                return $source->taskLabel();
            }
        }

        foreach ($this->checks->codes() as $code) {
            if ($name === MaintenanceChecks::historyName($code)) {
                return $this->checks->get($code)->label();
            }
        }

        return $name;
    }

    /**
     * @return EloquentBuilder<TaskRun>
     */
    private function finished(User $viewer): EloquentBuilder
    {
        return $this->visible($viewer)->whereIn('status', [TaskRunStatus::Success, TaskRunStatus::Failure]);
    }

    /**
     * @return EloquentBuilder<TaskRun>
     */
    private function visible(User $viewer): EloquentBuilder
    {
        return TaskRun::query()->where(function (Builder $query) use ($viewer) {
            $query->where('user_id', $viewer->id);

            if ($viewer->isAdmin()) {
                $query->orWhereNull('user_id');
            }
        });
    }
}

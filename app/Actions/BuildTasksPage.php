<?php

namespace App\Actions;

use App\Models\TaskRun;
use App\Models\User;
use App\Tasks\Data\ScheduleEntry;
use App\Tasks\Data\TaskRunItem;
use App\Tasks\ScheduledTask;
use App\Tasks\ScheduledTasks;
use App\Tasks\TaskRunHistory;

/**
 * The "Tasks" settings section: the viewer's recent runs and, for an admin,
 * the recurring schedule with each task's last run — so a "daily" task can be
 * seen really running, not just when it is due next.
 */
final readonly class BuildTasksPage
{
    public function __construct(private TaskRunHistory $history) {}

    /**
     * @return array{runs: list<TaskRunItem>, schedule: list<ScheduleEntry>, canClear: bool}
     */
    public function execute(User $viewer): array
    {
        return [
            'runs' => $this->history->recent($viewer),
            'schedule' => $viewer->isAdmin() ? $this->schedule() : [],
            'canClear' => $this->history->hasFinished($viewer),
        ];
    }

    /**
     * @return list<ScheduleEntry>
     */
    private function schedule(): array
    {
        $tasks = ScheduledTasks::all();
        $lastRuns = TaskRun::query()
            ->latestSystemRunOfEach(array_map(fn (ScheduledTask $task): string => $task->name->value, $tasks))
            ->get()
            ->keyBy('name');

        return array_map(function (ScheduledTask $task) use ($lastRuns): ScheduleEntry {
            $last = $lastRuns->get($task->name->value);

            return new ScheduleEntry(
                name: $task->name->value,
                label: $task->name->label(),
                frequency: $task->frequency,
                nextRunAt: $task->nextRunAt()->toIso8601String(),
                lastRunAt: $last?->finished_at?->toIso8601String() ?? $last?->queued_at->toIso8601String(),
                lastStatus: $last?->status,
            );
        }, $tasks);
    }
}

<?php

use App\Models\TaskRun;
use App\Tasks\ScheduledTasks;

// Scheduled tasks run the way the scheduler runs them (the queue is sync).

function runScheduledTask(string $name): TaskRun
{
    collect(ScheduledTasks::all())->firstWhere('name', $name)->queue->__invoke();

    return TaskRun::query()->where('name', $name)->latest('id')->firstOrFail();
}

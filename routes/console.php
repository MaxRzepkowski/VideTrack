<?php

use App\Tasks\Maintenance\MaintenanceChecks;
use App\Tasks\Maintenance\MaintenanceWindow;
use App\Tasks\ScheduledTasks;
use Illuminate\Support\Facades\Schedule;

// Background tasks. Definitions live in ScheduledTasks so the
// settings "Tasks" section can show the same frequency and next run —
// this file is only loaded in console context. Each queues a job that
// reports its lifecycle to `task_runs`, hence Schedule::call over
// Schedule::job.
foreach (ScheduledTasks::all() as $task) {
    Schedule::call($task->queue)->cron($task->cron)->name($task->name->value);
}

// The maintenance checks, window by window: a window queues its checks one
// after another. They keep their own state instead of a task run each, so a
// quiet check adds nothing to the task history.
foreach (MaintenanceWindow::cases() as $window) {
    Schedule::call(fn (MaintenanceChecks $checks) => $checks->queueWindow($window))->cron($window->cron())->name("maintenance-{$window->value}");
}

// Recovers runs the scheduler slept through (host not 24/7, container started
// after the cron minute): every half hour it queues any task overdue since its
// last due time — once, never a backlog. The entrypoint runs the same command
// on boot for an immediate catch-up (see docker/prod/entrypoint.sh).
Schedule::command('tasks:catch-up')->everyThirtyMinutes()->name('tasks-catch-up');

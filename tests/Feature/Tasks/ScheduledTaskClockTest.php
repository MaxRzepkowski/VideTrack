<?php

use App\Tasks\ScheduledTask;
use App\Tasks\TaskName;

test('the next run is counted from the app clock, which tests and snapshots can freeze', function () {
    $task = new ScheduledTask(TaskName::Cleanup, '0 */6 * * *', 'tasks.every_six_hours', fn () => null);

    $this->travelTo('2026-10-10 07:30:00');

    expect($task->nextRunAt()->toIso8601String())->toBe('2026-10-10T12:00:00+00:00');
});

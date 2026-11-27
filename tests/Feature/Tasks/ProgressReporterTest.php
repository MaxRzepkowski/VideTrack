<?php

use App\Models\TaskRun;
use App\Tasks\ProgressReporter;
use App\Tasks\TaskName;

test('progress is written at once, then at most once per interval, built only when written', function () {
    $run = TaskRun::queueTask(TaskName::RefreshCollections->value);
    $progress = new ProgressReporter($run);
    $built = 0;

    $progress->report(function () use (&$built): string {
        $built++;

        return 'Item 1 of 3…';
    });
    $progress->report(function () use (&$built): string {
        $built++;

        return 'Item 2 of 3…';
    });

    expect($run->refresh()->summary)->toBe('Item 1 of 3…')
        ->and($built)->toBe(1);
});

test('progress is written again once the interval has passed', function () {
    $run = TaskRun::queueTask(TaskName::RefreshCollections->value);
    $progress = new ProgressReporter($run);

    $progress->report(fn (): string => 'Item 1 of 3…');
    $this->travel(3)->seconds();
    $progress->report(fn (): string => 'Item 2 of 3…');

    expect($run->refresh()->summary)->toBe('Item 2 of 3…');
});

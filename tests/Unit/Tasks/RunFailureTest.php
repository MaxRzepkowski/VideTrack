<?php

use App\Support\ExternalServiceException;
use App\Tasks\Data\TaskLine;
use App\Tasks\RunFailure;

test('an outside service is worded in its own key and placeholders', function () {
    $failure = new class('tasks.fake_service_failure', ['service' => 'Example']) extends ExternalServiceException {};

    expect(RunFailure::of($failure))->toEqual(new TaskLine('tasks.fake_service_failure', ['service' => 'Example']));
});

test('any other exception says only that the run broke', function () {
    expect(RunFailure::of(new RuntimeException('select * from users where token = ?')))->toEqual(RunFailure::unexpected())
        ->and(RunFailure::unexpected()->key)->toBe(RunFailure::UNEXPECTED);
});

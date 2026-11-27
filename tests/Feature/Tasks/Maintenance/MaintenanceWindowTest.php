<?php

use App\Enums\TaskRunStatus;
use App\Jobs\RunMaintenanceChecks;
use App\Models\MaintenanceCheckState;
use App\Models\TaskRun;
use App\Models\User;
use App\Support\ExternalServiceException;
use App\Tasks\Data\TaskLine;
use App\Tasks\Maintenance\MaintenanceChecks;
use App\Tasks\Maintenance\MaintenanceRegistry;
use App\Tasks\Maintenance\MaintenanceWindow;
use App\Tasks\RunFailure;
use App\Tasks\TaskRunHistory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMaintenanceCheck;
use Tests\Fakes\SecondFakeMaintenanceCheck;

beforeEach(function () {
    $registry = app(MaintenanceRegistry::class);
    $registry->register(SecondFakeMaintenanceCheck::CODE, SecondFakeMaintenanceCheck::class);
    $registry->register(FakeMaintenanceCheck::CODE, FakeMaintenanceCheck::class);
});

function checkState(string $code): ?MaintenanceCheckState
{
    return MaintenanceCheckState::query()->where('code', $code)->first();
}

test('a window runs its checks by their order and keeps what each saw', function () {
    app(MaintenanceChecks::class)->queueWindow(MaintenanceWindow::Nightly);

    expect(FakeMaintenanceCheck::$ran)->toBe([FakeMaintenanceCheck::class, SecondFakeMaintenanceCheck::class]);

    $state = checkState(FakeMaintenanceCheck::CODE);

    expect($state->status)->toBe(TaskRunStatus::Success)
        ->and($state->checked_at->eq(now()))->toBeTrue()
        ->and($state->result)->toBe([(new TaskLine(FakeMaintenanceCheck::$results[0]))->stored()])
        ->and($state->error)->toBeNull();
});

test('the checks of every window run window by window, each by its order', function () {
    FakeMaintenanceCheck::$window = MaintenanceWindow::Weekly;
    SecondFakeMaintenanceCheck::$window = MaintenanceWindow::Frequent;
    SecondFakeMaintenanceCheck::$order = 99;

    expect(array_keys(app(MaintenanceRegistry::class)->applicable()))
        ->toBe([SecondFakeMaintenanceCheck::CODE, FakeMaintenanceCheck::CODE]);
});

test('a window runs only its own checks', function () {
    SecondFakeMaintenanceCheck::$window = MaintenanceWindow::Weekly;

    app(MaintenanceChecks::class)->queueWindow(MaintenanceWindow::Nightly);

    expect(FakeMaintenanceCheck::$ran)->toBe([FakeMaintenanceCheck::class])
        ->and(checkState(SecondFakeMaintenanceCheck::CODE))->toBeNull();
});

test('a check this instance does not need is never run', function () {
    SecondFakeMaintenanceCheck::$applies = false;

    app(MaintenanceChecks::class)->queueWindow(MaintenanceWindow::Nightly);

    expect(FakeMaintenanceCheck::$ran)->toBe([FakeMaintenanceCheck::class])
        ->and(checkState(SecondFakeMaintenanceCheck::CODE))->toBeNull();
});

test('a window without checks queues nothing', function () {
    Queue::fake();

    app(MaintenanceChecks::class)->queueWindow(MaintenanceWindow::Frequent);

    Queue::assertNothingPushed();
});

test('a window queues its checks as one run, in their order', function () {
    Queue::fake();

    app(MaintenanceChecks::class)->queueWindow(MaintenanceWindow::Nightly);

    Queue::assertPushed(RunMaintenanceChecks::class, fn (RunMaintenanceChecks $job): bool => $job->codes === [FakeMaintenanceCheck::CODE, SecondFakeMaintenanceCheck::CODE]);
});

test('a quiet run leaves no task history', function () {
    app(MaintenanceChecks::class)->queueWindow(MaintenanceWindow::Nightly);

    expect(TaskRun::query()->count())->toBe(0);
});

test('a run that noted something leaves it in the task history under the check\'s name', function () {
    FakeMaintenanceCheck::$notes = ['tasks.fake_check_note', 'tasks.fake_check_second_note'];

    app(MaintenanceChecks::class)->queueWindow(MaintenanceWindow::Nightly);

    $run = TaskRun::query()->sole();
    $admin = User::factory()->admin()->create();

    expect($run->name)->toBe(MaintenanceChecks::historyName(FakeMaintenanceCheck::CODE))
        ->and($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->summary)->toBe(__('tasks.fake_check_note'))
        ->and($run->details)->toBe([__('tasks.fake_check_second_note')])
        ->and(app(TaskRunHistory::class)->recent($admin)[0]->label)->toBe((new FakeMaintenanceCheck)->label());
});

test('a check that breaks says so in its row and the history, and the next one still runs', function () {
    Exceptions::fake();
    FakeMaintenanceCheck::$failure = new RuntimeException('Something deep down broke.');

    app(MaintenanceChecks::class)->queueWindow(MaintenanceWindow::Nightly);

    $state = checkState(FakeMaintenanceCheck::CODE);

    expect(FakeMaintenanceCheck::$ran)->toBe([FakeMaintenanceCheck::class, SecondFakeMaintenanceCheck::class])
        ->and($state->status)->toBe(TaskRunStatus::Failure)
        ->and($state->error)->toBe(RunFailure::unexpected()->stored())
        ->and(checkState(SecondFakeMaintenanceCheck::CODE)->status)->toBe(TaskRunStatus::Success);

    $run = TaskRun::query()->sole();

    expect($run->status)->toBe(TaskRunStatus::Failure)
        ->and($run->summary)->toBe(RunFailure::unexpected()->wording())
        ->and($run->summary)->not->toContain('deep down');

    Exceptions::assertReported(RuntimeException::class);
});

test('an outside service that failed a check is named in its own words', function () {
    Exceptions::fake();
    FakeMaintenanceCheck::$failure = new class('tasks.fake_service_failure', ['service' => 'Example']) extends ExternalServiceException {};

    app(MaintenanceChecks::class)->queueWindow(MaintenanceWindow::Nightly);

    expect(checkState(FakeMaintenanceCheck::CODE)->error)
        ->toBe((new TaskLine('tasks.fake_service_failure', ['service' => 'Example']))->stored());
});

test('a check its twin is at work on is left to the twin', function () {
    Cache::lock(MaintenanceChecks::lockName(FakeMaintenanceCheck::CODE), RunMaintenanceChecks::TIMEOUT_SECONDS)->get();

    app(MaintenanceChecks::class)->queueWindow(MaintenanceWindow::Nightly);

    expect(FakeMaintenanceCheck::$ran)->toBe([SecondFakeMaintenanceCheck::class])
        ->and(checkState(FakeMaintenanceCheck::CODE))->toBeNull();
});

test('a check queued before its module was switched off leaves nothing behind', function () {
    MaintenanceCheckState::factory()->pending()->create(['code' => FakeMaintenanceCheck::CODE]);
    FakeMaintenanceCheck::$applies = false;

    app(MaintenanceChecks::class)->perform('run-of-alex', [FakeMaintenanceCheck::CODE]);

    expect(FakeMaintenanceCheck::$ran)->toBe([])
        ->and(checkState(FakeMaintenanceCheck::CODE))->toBeNull();
});

test('a run the worker gave up on ends the check it left at work, in its row and the history', function () {
    $job = new RunMaintenanceChecks([FakeMaintenanceCheck::CODE, SecondFakeMaintenanceCheck::CODE]);
    MaintenanceCheckState::factory()->started(now())->create(['code' => FakeMaintenanceCheck::CODE, 'run_id' => $job->runId]);

    $job->failed(new RuntimeException('Timed out.'));

    expect(checkState(FakeMaintenanceCheck::CODE)->status)->toBe(TaskRunStatus::Failure)
        ->and(checkState(FakeMaintenanceCheck::CODE)->error)->toBe(RunFailure::unexpected()->stored())
        ->and(TaskRun::query()->sole()->status)->toBe(TaskRunStatus::Failure);
});

test('a run the worker gave up on leaves alone a check another run has taken', function () {
    $job = new RunMaintenanceChecks([FakeMaintenanceCheck::CODE]);
    $twin = new RunMaintenanceChecks([FakeMaintenanceCheck::CODE]);
    MaintenanceCheckState::factory()->started(now())->create(['code' => FakeMaintenanceCheck::CODE, 'run_id' => $twin->runId]);

    $job->failed(new RuntimeException('Timed out.'));

    expect(checkState(FakeMaintenanceCheck::CODE)->status)->toBe(TaskRunStatus::Started)
        ->and(TaskRun::query()->count())->toBe(0);
});

test('a check keeps the id of the run that took it', function () {
    $job = new RunMaintenanceChecks([FakeMaintenanceCheck::CODE]);

    dispatch($job);

    expect(checkState(FakeMaintenanceCheck::CODE)->run_id)->toBe($job->runId);
});

test('catching up queues a window whose checks have not run since it was due', function () {
    $checks = app(MaintenanceChecks::class);
    $due = MaintenanceWindow::Nightly->previousRunAt(now());

    MaintenanceCheckState::factory()->checkedAt($due->copy()->addMinute())->create(['code' => FakeMaintenanceCheck::CODE]);
    $second = MaintenanceCheckState::factory()->checkedAt($due->copy()->addMinute())->create(['code' => SecondFakeMaintenanceCheck::CODE]);

    expect($checks->overdueWindows(now()))->toBe([]);

    $second->update(['checked_at' => $due->copy()->subMinute()]);

    expect($checks->overdueWindows(now()))->toBe([MaintenanceWindow::Nightly]);

    $second->update(['status' => TaskRunStatus::Pending]);

    expect($checks->overdueWindows(now()))->toBe([]);
});

test('the catch-up command queues an overdue window', function () {
    Queue::fake();

    $this->artisan('tasks:catch-up')
        ->expectsOutputToContain('Caught up overdue maintenance window ['.MaintenanceWindow::Nightly->value.'].')
        ->assertSuccessful();

    Queue::assertPushed(RunMaintenanceChecks::class);
});

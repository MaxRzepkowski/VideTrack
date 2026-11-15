<?php

use App\Enums\TaskRunStatus;
use App\Jobs\Concerns\RunsAsTask;
use App\Jobs\Middleware\OneRunAtATime;
use App\Jobs\RefreshCollections;
use App\Jobs\RunImport;
use App\Models\TaskRun;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One pass of the worker over the database queue, the way the queue container
 * runs it (its --tries=3 is the default a job without a budget of its own
 * gets).
 */
function workOnce(): void
{
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(maxTries: 3));
}

/** The lock a run of this task holds while it works. */
function holdTaskLock(object $job, TaskRun $run): void
{
    Cache::lock(OneRunAtATime::lockName("{$run->name}:{$run->user_id}"), $job->timeout)->get();
}

test('a run waiting behind its twin keeps its place however long the twin takes, and then runs', function () {
    // A long task, so its twin's lock outlives more looks than the worker has tries.
    $run = TaskRun::queueTask('refresh-collections');
    $job = new RefreshCollections($run);
    holdTaskLock($job, $run);

    dispatch($job)->onConnection('database');

    foreach (range(1, 5) as $look) {
        workOnce();
        $this->travel(RefreshCollections::OVERLAP_RECHECK_SECONDS + 1)->seconds();
    }

    // Each look leaves a fresh copy: no try is spent on waiting.
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('attempts'))->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($run->refresh()->status)->toBe(TaskRunStatus::Pending);

    Cache::lock(OneRunAtATime::lockName('refresh-collections:'))->forceRelease();
    workOnce();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($run->refresh()->status)->toBe(TaskRunStatus::Success);
});

test('a run that sat in the queue far longer than its timeout still runs', function () {
    // One worker: a long import or refresh ahead of a daily task keeps it
    // waiting before it is ever picked up.
    $run = TaskRun::queueTask('refresh-collections');
    $job = new RefreshCollections($run);

    dispatch($job)->onConnection('database');
    $this->travel(10 * $job->timeout)->seconds();
    workOnce();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($run->refresh()->status)->toBe(TaskRunStatus::Success);
});

test('a run frees the task when it ends, also when it breaks', function () {
    $run = TaskRun::queueTask('refresh-collections');
    $job = new RefreshCollections($run);

    (new OneRunAtATime('refresh-collections:', $job->timeout, 60, fn () => $job))
        ->handle($job, fn () => throw new RuntimeException('broke'));
})->throws(RuntimeException::class)->after(function () {
    expect(Cache::lock(OneRunAtATime::lockName('refresh-collections:'), 60)->get())->toBeTrue();
});

test('only an exception spends a task\'s tries, never a wait', function () {
    $tasks = collect(glob(app_path('Jobs/*.php')))
        ->map(fn (string $file) => new ReflectionClass('App\\Jobs\\'.basename($file, '.php')))
        ->filter(fn (ReflectionClass $job) => in_array(RunsAsTask::class, $job->getTraitNames(), true));

    expect($tasks)->not->toBeEmpty();

    foreach ($tasks as $task) {
        expect($task->getDefaultProperties())->toHaveKey('maxExceptions')->not->toHaveKey('tries');
    }

    expect((new ReflectionClass(RunImport::class))->getDefaultProperties()['maxExceptions'])->toBe(2);
});

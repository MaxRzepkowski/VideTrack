<?php

use App\Catalog\Creators\CreatorTable;
use App\Jobs\EnrichShowSchedule;
use App\Jobs\PrefetchTrending;
use App\Jobs\RefreshCollections;
use App\Jobs\SyncFollowedCreator;
use App\Models\TaskRun;
use App\Support\ServiceUnavailable;
use App\Tasks\RunFailure;
use App\Tasks\TaskName;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

test('a provider that cannot serve trending is named by its code in the run', function () {
    Sleep::fake();
    Http::fake(['*trending*' => Http::response(null, 503)]);
    $run = TaskRun::queueTask(TaskName::PrefetchTrending->value);

    app()->call([new PrefetchTrending($run), 'handle']);

    expect($run->refresh()->errors)->not->toBeEmpty()
        ->and($run->errors[0])->toStartWith('tmdb en:');
});

test('a show whose air times could not be read after the last try is logged without the request', function () {
    Log::spy();

    (new EnrichShowSchedule(42))->failed(new RuntimeException('GET https://api.tvmaze.com/shows?secret=1 failed'));

    Log::shouldHaveReceived('warning')->once()->with('Air times of a show were not refreshed.', ['show' => 42, 'error' => RuntimeException::class]);
});

test('a task that breaks keeps the raw exception out of its run, but says which service failed', function () {
    $broken = TaskRun::queueTask(TaskName::RefreshCollections->value);
    (new RefreshCollections($broken))->failed(new RuntimeException('SQLSTATE[HY000]: /var/www/database/database.sqlite'));

    $unavailable = TaskRun::queueTask(TaskName::RefreshCollections->value);
    (new RefreshCollections($unavailable))->failed(new ServiceUnavailable('TMDB answered 503.'));

    expect($broken->refresh()->summary)->toBe(__(RunFailure::UNEXPECTED))
        ->and($unavailable->refresh()->summary)->toBe('TMDB answered 503.');
});

test('a pass already waiting in the queue is not queued a second time', function () {
    Queue::fake();

    EnrichShowSchedule::dispatch(7);
    EnrichShowSchedule::dispatch(7);
    EnrichShowSchedule::dispatch(8);
    SyncFollowedCreator::dispatch(CreatorTable::People, 3);
    SyncFollowedCreator::dispatch(CreatorTable::People, 3);
    SyncFollowedCreator::dispatch(CreatorTable::Companies, 3);

    Queue::assertPushed(EnrichShowSchedule::class, 2);
    Queue::assertPushed(SyncFollowedCreator::class, 2);
});

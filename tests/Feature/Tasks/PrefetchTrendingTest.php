<?php

use App\Enums\TaskRunStatus;
use App\Jobs\PrefetchTrending;
use App\Models\TaskRun;
use Illuminate\Support\Facades\Http;

test('prefetch trending warms the cache for every supported locale', function () {
    Http::fake(['*/trending/*/week*' => Http::response(jsonFixture('tmdb/search'))]);

    PrefetchTrending::queueTask();

    $run = TaskRun::query()->firstOrFail();

    expect($run->name)->toBe('prefetch-trending')
        ->and($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->summary)->toContain('in locales: 2');
});

<?php

use App\Enums\TaskRunStatus;
use App\Jobs\PrefetchTrending;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('prefetch trending warms the cache for every language somebody reads in', function () {
    Http::fake(['*/trending/*/week*' => Http::response(jsonFixture('tmdb/search'))]);
    User::factory()->create(['locale' => 'pl']);

    PrefetchTrending::queueTask();

    $run = TaskRun::query()->firstOrFail();

    expect($run->name)->toBe('prefetch-trending')
        ->and($run->status)->toBe(TaskRunStatus::Success)
        ->and($run->summary)->toContain('in locales: 2');
});

test('a language nobody reads in is not warmed', function () {
    Http::fake(['*/trending/*/week*' => Http::response(jsonFixture('tmdb/search'))]);
    User::factory()->create(['locale' => 'en']);

    PrefetchTrending::queueTask();

    expect(TaskRun::query()->firstOrFail()->summary)->toContain('in locales: 1');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'language=pl'));
});

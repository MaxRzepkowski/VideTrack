<?php

use App\Catalog\ProviderHealth;
use App\Catalog\ProviderKeyRejected;
use App\Jobs\PrefetchTrending;
use App\Jobs\RefreshEntityMetadata;
use App\Models\Company;
use App\Models\TaskRun;
use App\Tasks\TaskName;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** TMDB has turned the instance's key away, as a refused request leaves it. */
function tmdbRefusedTheKey(): void
{
    try {
        app(ProviderHealth::class)->watch('tmdb', fn () => throw new ProviderKeyRejected('TMDB request failed with status 401.'));
    } catch (ProviderKeyRejected) {
        // The state is what the test needs.
    }
}

test('the trending prefetch leaves a provider that refuses the key unasked, and says so once', function () {
    Http::fake();
    tmdbRefusedTheKey();

    PrefetchTrending::queueTask();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'themoviedb'));
    expect(TaskRun::query()->where('name', TaskName::PrefetchTrending->value)->sole()->errors)
        ->toContain("tmdb: 1 left unasked, it refuses the instance's key.");
});

test('the people and companies refresh leaves a provider that refuses the key unasked', function () {
    Http::fake();
    Company::factory()->count(3)->create(['provider' => 'tmdb']);
    tmdbRefusedTheKey();

    RefreshEntityMetadata::queueTask();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'themoviedb'));
    expect(TaskRun::query()->where('name', TaskName::RefreshEntities->value)->sole()->errors)
        ->toBe(["tmdb: 3 left unasked, it refuses the instance's key."]);
});

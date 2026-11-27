<?php

use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Catalog\ProviderHealth;
use App\Catalog\ProviderKeyRejected;
use App\Catalog\ProviderRejected;
use App\Catalog\Providers\ComicVine\ComicVineProvider;
use App\Catalog\Providers\Hardcover\HardcoverProvider;
use App\Catalog\Providers\Igdb\IgdbProvider;
use App\Catalog\Providers\Mal\MalProvider;
use App\Catalog\Providers\Tmdb\TmdbClient;
use App\Catalog\Providers\Tmdb\TmdbProvider;
use App\Catalog\ProviderState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tmdb = fakeTmdbStatus();
});

/**
 * TMDB answers every request with the status the test last set: the first
 * fake that matches wins, so one fake reads it for the whole test.
 */
function fakeTmdbStatus(): object
{
    $answer = new class
    {
        public int $status = 200;
    };

    Http::fake(['api.themoviedb.org/*' => fn () => Http::response(['images' => []], $answer->status)]);

    return $answer;
}

/** What TMDB's state is after one uncached request answered with $status. */
function tmdbAnswering(int $status): ProviderState
{
    test()->tmdb->status = $status;

    try {
        app(TmdbProvider::class)->probe();
    } catch (ProviderException|ProviderRejected|ItemNotFoundException) {
        // The state is what the test reads.
    }

    return app(ProviderHealth::class)->of('tmdb')->state;
}

test('a provider nobody has heard from counts as healthy', function () {
    expect(app(ProviderHealth::class)->of('tmdb')->state)->toBe(ProviderState::Healthy)
        ->and(app(ProviderHealth::class)->of('tmdb')->since)->toBeNull();
});

test('what an answer says of the provider', function (int $status, ProviderState $state) {
    expect(tmdbAnswering($status))->toBe($state);
})->with([
    'an answer' => [200, ProviderState::Healthy],
    'not found, which is an answer' => [404, ProviderState::Healthy],
    'a request it does not take, with the key taken' => [400, ProviderState::Healthy],
    'the key refused' => [401, ProviderState::KeyRejected],
    'a scope or an operation the key may not ask for, not the key' => [403, ProviderState::Healthy],
    'a limit' => [429, ProviderState::Degraded],
    'an outage' => [503, ProviderState::Degraded],
]);

test('a refused key is a refusal of its own kind', function () {
    $this->tmdb->status = 401;

    expect(fn () => app(TmdbProvider::class)->probe())->toThrow(ProviderKeyRejected::class);
});

test('a dropped connection marks the provider degraded', function () {
    Http::swap(new Factory);
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    try {
        app(TmdbProvider::class)->probe();
    } catch (ProviderException) {
        // The state is what the test reads.
    }

    expect(app(ProviderHealth::class)->of('tmdb')->state)->toBe(ProviderState::Degraded);
});

test('an answer ends a refused key, an outage does not', function () {
    tmdbAnswering(401);
    $since = app(ProviderHealth::class)->of('tmdb')->since;

    $this->travel(5)->minutes();

    expect(tmdbAnswering(503))->toBe(ProviderState::KeyRejected)
        ->and(app(ProviderHealth::class)->of('tmdb')->since->eq($since))->toBeTrue()
        ->and(tmdbAnswering(200))->toBe(ProviderState::Healthy)
        ->and(app(ProviderHealth::class)->of('tmdb')->answeredAt->eq(now()))->toBeTrue();
});

test('a refresh writes the same state down once a minute, not once a request', function () {
    tmdbAnswering(200);
    $first = app(ProviderHealth::class)->of('tmdb')->answeredAt;

    $this->travel(ProviderHealth::REWRITE_AFTER_SECONDS - 1)->seconds();
    tmdbAnswering(200);

    expect(app(ProviderHealth::class)->of('tmdb')->answeredAt->eq($first))->toBeTrue();

    $this->travel(1)->seconds();
    tmdbAnswering(200);

    expect(app(ProviderHealth::class)->of('tmdb')->answeredAt->eq(now()))->toBeTrue();
});

test('a cached answer asks nothing and changes nothing', function () {
    app(TmdbClient::class)->get('configuration', [], 60);
    tmdbAnswering(401);
    $this->tmdb->status = 200;

    app(TmdbClient::class)->get('configuration', [], 60);

    expect(app(ProviderHealth::class)->of('tmdb')->state)->toBe(ProviderState::KeyRejected);
});

test('ComicVine names a refused key in its answer', function () {
    enableComicsModule();
    Http::fake(['comicvine.gamespot.com/*' => Http::response(['status_code' => 100, 'error' => 'Invalid API Key'])]);

    expect(fn () => app(ComicVineProvider::class)->probe())->toThrow(ProviderKeyRejected::class)
        ->and(app(ProviderHealth::class)->of('comicvine')->state)->toBe(ProviderState::KeyRejected);
});

test('Twitch turning away the IGDB credentials is a refused key', function () {
    config(['tracker.igdb.client_id' => 'testing-id', 'tracker.igdb.client_secret' => 'testing-secret']);
    Http::fake(['id.twitch.tv/*' => Http::response(['status' => 403, 'message' => 'invalid client secret'], 403)]);

    expect(fn () => app(IgdbProvider::class)->probe())->toThrow(ProviderKeyRejected::class)
        ->and(app(ProviderHealth::class)->of('igdb')->state)->toBe(ProviderState::KeyRejected);
});

test('a refused key keeps what the service answered', function () {
    $this->tmdb->status = 401;
    tmdbAnswering(401);

    $failure = app(ProviderHealth::class)->of('tmdb')->failure;

    expect($failure->status)->toBe(401)
        ->and($failure->at->eq(now()))->toBeTrue();
});

test('Hardcover refusing an expired key is a refused key', function () {
    enableBooksModule();
    Http::fake(['api.hardcover.app/*' => Http::response(['error' => 'invalid_token', 'error_description' => 'Token expired'], 401)]);

    expect(fn () => app(HardcoverProvider::class)->probe())->toThrow(ProviderKeyRejected::class)
        ->and(app(ProviderHealth::class)->of('hardcover')->state)->toBe(ProviderState::KeyRejected);
});

test('every probe asks for one title by id, the question the app asks every day', function (Closure $enable, string $provider, Closure $asked) {
    $enable();
    Http::swap(new Factory);
    Http::fake([
        'id.twitch.tv/*' => Http::response(['access_token' => 'token-of-alex', 'expires_in' => 5000000]),
        '*' => Http::response(['status_code' => 1, 'results' => [], 'data' => ['books_by_pk' => null]]),
    ]);

    app($provider)->probe();

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'twitch') && $asked($request));
})->with([
    'TMDB' => [fn () => null, TmdbProvider::class, fn (Request $request) => str_contains($request->url(), '/movie/'.TmdbProvider::PROBE_TITLE)],
    'IGDB' => [fn () => config(['tracker.igdb.client_id' => 'id-of-alex', 'tracker.igdb.client_secret' => 'secret-of-alex']), IgdbProvider::class, fn (Request $request) => str_ends_with($request->url(), '/games') && $request->body() === 'fields id; where id = '.IgdbProvider::PROBE_TITLE.';'],
    'MyAnimeList' => [fn () => enableMangaModule(), MalProvider::class, fn (Request $request) => str_contains($request->url(), '/manga/'.MalProvider::PROBE_TITLE.'?fields=id')],
    'Hardcover' => [fn () => enableBooksModule(), HardcoverProvider::class, fn (Request $request) => str_contains((string) data_get($request->data(), 'query'), 'books_by_pk') && data_get($request->data(), 'variables.id') === HardcoverProvider::PROBE_TITLE],
    'ComicVine' => [fn () => enableComicsModule(), ComicVineProvider::class, fn (Request $request) => str_contains($request->url(), '/volume/'.ComicVineProvider::VOLUME_PREFIX.'-'.ComicVineProvider::PROBE_TITLE.'/') && str_contains($request->url(), 'field_list=id')],
]);

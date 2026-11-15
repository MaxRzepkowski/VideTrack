<?php

use App\Catalog\Providers\ComicVine\ComicVineProvider;
use App\Catalog\Providers\Hardcover\HardcoverProvider;
use App\Catalog\Providers\Igdb\IgdbProvider;
use App\Catalog\Providers\Mal\MalClient;
use App\Catalog\Providers\Mal\MalProvider;
use App\Catalog\Providers\Tvmaze\TvmazeClient;
use Illuminate\Support\Facades\Http;

// The empty-listing cache guard (MakesCachedRequests) proven across every
// response shape: a transient hiccup that returns an empty listing must NOT be
// cached, so the next request self-heals instead of freezing an empty page for
// the whole TTL. Mirrors TmdbCacheGuardTest for the four other providers.

test('IGDB: a degraded (empty) search is not cached, so the next request self-heals', function () {
    enableGamesModule();
    Http::fake([
        'id.twitch.tv/*' => Http::response(['access_token' => 'testing-token', 'expires_in' => 5000000]),
        '*/games' => Http::sequence()->push([])->push(jsonFixture('igdb/search')),
    ]);

    $provider = app(IgdbProvider::class);

    expect($provider->search('hollow knight', 'en'))->toBeEmpty();
    expect($provider->search('hollow knight', 'en'))->toHaveCount(2);
});

test('Hardcover: a degraded (empty) search is not cached, so the next request self-heals', function () {
    enableBooksModule();
    Http::fake([
        'api.hardcover.app/*' => Http::sequence()
            ->push(['data' => ['search' => ['results' => ['hits' => []]]]])
            ->push(jsonFixture('hardcover/search')),
    ]);

    $provider = app(HardcoverProvider::class);

    expect($provider->search('hobbit', 'en'))->toBeEmpty();
    // All three fixture hits, including the coverless book, prove the empty
    // response was not cached and the complete retry reached the provider.
    expect($provider->search('hobbit', 'en'))->toHaveCount(3);
});

test('MAL: a degraded (empty) search is not cached, so the next request self-heals', function () {
    enableMangaModule();
    Http::fake([
        '*/manga*' => Http::sequence()->push(['data' => []])->push(jsonFixture('mal/search')),
    ]);

    $provider = app(MalProvider::class);

    expect($provider->search('berserk', 'en'))->toBeEmpty();
    expect($provider->search('berserk', 'en'))->not->toBeEmpty();
});

test('ComicVine: a degraded (empty) search is not cached, so the next request self-heals', function () {
    // More requests than the provider allows per second: its rate limiter
    // waits for the window to pass, which a frozen clock never lets happen.
    $this->travelBack();

    enableComicsModule();
    Http::fake([
        '*/search/*' => Http::sequence()
            ->push(['status_code' => 1, 'results' => []])
            ->push(jsonFixture('comicvine/search')),
    ]);

    $provider = app(ComicVineProvider::class);

    expect($provider->search('saga', 'en'))->toBeEmpty();
    expect($provider->search('saga', 'en'))->not->toBeEmpty();
});

test('a non-empty listing is cached — the second identical search hits no provider', function () {
    enableBooksModule();
    fakeHardcover(['Search' => 'search']);

    $provider = app(HardcoverProvider::class);

    $provider->search('hobbit', 'en');
    $provider->search('hobbit', 'en');

    Http::assertSentCount(1);
});

test('the same query asked with its parameters in another order is one cache entry', function () {
    enableMangaModule();
    Http::fake(['api.myanimelist.net/*' => Http::response(['data' => [['node' => ['id' => 1]]]])]);
    $client = app(MalClient::class);

    $client->get('manga', ['q' => 'berserk', 'limit' => 10], 60);
    $client->get('manga', ['limit' => 10, 'q' => 'berserk'], 60);

    Http::assertSentCount(1);
});

test('an answer that is null or false is a cache hit like any other', function () {
    Http::fake(['api.tvmaze.com/*' => Http::response(null, 404)]);
    $client = app(TvmazeClient::class);

    expect($client->get('lookup/shows', ['thetvdb' => 1]))->toBeNull()
        ->and($client->get('lookup/shows', ['thetvdb' => 1]))->toBeNull()
        ->and($client->getCached('lookup/shows', ['thetvdb' => 1]))->toBeNull();

    Http::assertSentCount(1);
});

<?php

use App\Catalog\ProviderBusy;
use App\Catalog\ProviderException;
use App\Catalog\Providers\Tmdb\TmdbClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

test('a degraded listing (empty required key) is not cached, so the next request self-heals', function () {
    // A transient provider hiccup returns an empty listing, then real data.
    Http::fakeSequence()
        ->push(['results' => []])
        ->push(['results' => [['id' => 1, 'name' => 'Show One']]]);

    $client = app(TmdbClient::class);

    // First: empty — must NOT be cached.
    expect($client->get('tv/popular', ['page' => 1], 3600, 'results')['results'])->toBe([]);
    // Second: re-fetched (the empty was not cached) and gets the real data.
    expect($client->get('tv/popular', ['page' => 1], 3600, 'results')['results'])->toBe([['id' => 1, 'name' => 'Show One']]);
});

test('an empty certification dictionary is not cached for a month', function () {
    Http::fakeSequence()
        ->push(['certifications' => []])
        ->push(['certifications' => ['US' => [['certification' => 'R']]]]);

    $client = app(TmdbClient::class);

    expect($client->get('certification/movie/list', [], 60 * 60 * 24 * 30, 'certifications')['certifications'])->toBe([])
        ->and($client->get('certification/movie/list', [], 60 * 60 * 24 * 30, 'certifications')['certifications'])->toHaveKey('US');

    Http::assertSentCount(2);
});

test('an empty root-list dictionary is not cached for a month', function () {
    Http::fakeSequence()
        ->push([])
        ->push([['iso_639_1' => 'en', 'english_name' => 'English']]);

    $client = app(TmdbClient::class);

    expect($client->getList('configuration/languages', [], 60 * 60 * 24 * 30))->toBe([])
        ->and($client->getList('configuration/languages', [], 60 * 60 * 24 * 30))->toHaveCount(1);

    Http::assertSentCount(2);
});

test('a non-empty listing is cached — the second request hits no provider', function () {
    Http::fake(['*' => Http::response(['results' => [['id' => 1]]])]);

    $client = app(TmdbClient::class);

    $client->get('tv/popular', ['page' => 1], 3600, 'results');
    $client->get('tv/popular', ['page' => 1], 3600, 'results');

    Http::assertSentCount(1);
});

test('detail responses (no required key) always cache', function () {
    Http::fake(['*' => Http::response(['id' => 603, 'title' => 'The Matrix'])]);

    $client = app(TmdbClient::class);

    $client->get('movie/603', [], 3600);
    $client->get('movie/603', [], 3600);

    Http::assertSentCount(1);
});

test('a detail answered with an empty or unreadable body is an outage, and is not cached', function () {
    Http::fakeSequence()
        ->push('', 200)
        ->push('<html>gateway</html>', 200)
        ->push(['id' => 603, 'title' => 'The Matrix']);

    $client = app(TmdbClient::class);

    expect(fn () => $client->get('movie/603', [], 3600))->toThrow(ProviderException::class)
        ->and(fn () => $client->get('movie/603', [], 3600))->toThrow(ProviderException::class)
        ->and($client->get('movie/603', [], 3600)['id'])->toBe(603);
});

test('a 429 is a busy provider that says when to ask again', function (array $headers, int $seconds) {
    Http::fake(['api.themoviedb.org/3/movie/603*' => Http::response(['status_code' => 25], 429, $headers)]);

    try {
        app(TmdbClient::class)->get('movie/603', [], 3600);
        $this->fail('The 429 went unnoticed.');
    } catch (ProviderBusy $busy) {
        expect($busy->retryAfterSeconds)->toBe($seconds);
    }

    // Asked once: an answered 429 is waited out, not retried on the spot.
    Http::assertSentCount(1);
})->with([
    'with Retry-After' => [['Retry-After' => '12'], 12],
    'without it' => [[], ProviderBusy::DEFAULT_RETRY_AFTER_SECONDS],
]);

test('a limiter that never frees a slot makes the provider busy, not hung', function () {
    Sleep::fake();
    // The frozen clock never lets the one-second window pass.
    RateLimiter::increment('tmdb-api', 1, TmdbClient::RATE_LIMIT_PER_SECOND);
    Http::fake();

    expect(fn () => app(TmdbClient::class)->get('movie/603', [], 3600))->toThrow(ProviderBusy::class);
    Http::assertNothingSent();
});

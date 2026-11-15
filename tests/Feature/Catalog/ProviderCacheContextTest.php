<?php

use App\Catalog\ProviderCacheContext;
use App\Catalog\Providers\Tmdb\TmdbClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

// A manual refresh bypasses the provider response cache and nothing else. The
// rate limiters that keep us under third-party API limits, and every atomic
// Cache::add() in the app, must behave exactly as outside a refresh.

test('a manual refresh does not reset the rate limiters', function () {
    RateLimiter::hit('tmdb-api');
    RateLimiter::hit('tmdb-api');

    app(ProviderCacheContext::class)->fresh(function (): void {
        expect(RateLimiter::attempts('tmdb-api'))->toBe(2);

        RateLimiter::hit('tmdb-api');
    });

    expect(RateLimiter::attempts('tmdb-api'))->toBe(3);
});

test('Cache::add stays atomic during a manual refresh', function () {
    Cache::put('webhook-delivery', 'first', 60);

    app(ProviderCacheContext::class)->fresh(function (): void {
        expect(Cache::add('webhook-delivery', 'second', 60))->toBeFalse();
    });

    expect(Cache::get('webhook-delivery'))->toBe('first');
});

test('a manual refresh still reads values that are not provider responses', function () {
    Cache::put('igdb:access-token', 'token', 60);

    app(ProviderCacheContext::class)->fresh(function (): void {
        expect(Cache::get('igdb:access-token'))->toBe('token');
    });
});

test('a manual refresh asks each address once, and the next refresh asks again', function () {
    Http::fake(['api.themoviedb.org/3/movie/603*' => Http::response(['id' => 603])]);
    $client = app(TmdbClient::class);

    app(ProviderCacheContext::class)->fresh(function () use ($client): void {
        $client->get('movie/603', [], 60);
        $client->get('movie/603', [], 60);
    });

    Http::assertSentCount(1);

    app(ProviderCacheContext::class)->fresh(fn (): array => $client->get('movie/603', [], 60));

    Http::assertSentCount(2);
});

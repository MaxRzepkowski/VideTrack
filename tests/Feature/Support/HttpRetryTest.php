<?php

use App\Catalog\MatureContent;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRegistry;
use App\Catalog\ProviderRejected;
use Illuminate\Support\Facades\Http;

/*
 * An answered 4xx is never asked again: retrying a wrong key turns one 401
 * into a burst, and a burst of 401s is how an IP gets banned. Only a dropped
 * connection or a 5xx earns a second try.
 */

test('an answered 4xx is sent exactly once, and reads as a refusal', function () {
    Http::fake(['*/search/multi*' => Http::response(['status_message' => 'Invalid API key'], 401)]);

    expect(fn () => app(ProviderRegistry::class)->get('tmdb')->search('Matrix', 'en', MatureContent::Suggestive))
        ->toThrow(ProviderRejected::class);

    Http::assertSentCount(1);
});

test('a 5xx earns another try', function () {
    Http::fake(['*/search/multi*' => Http::sequence()
        ->push(['status_message' => 'Internal error'], 500)
        ->push(jsonFixture('tmdb/search'))]);

    app(ProviderRegistry::class)->get('tmdb')->search('Matrix', 'en', MatureContent::Suggestive);

    Http::assertSentCount(2);
});

test('a provider that asks to wait, or is down, is not refusing', function (int $status) {
    Http::fake(['*/search/multi*' => Http::response([], $status)]);

    expect(fn () => app(ProviderRegistry::class)->get('tmdb')->search('Matrix', 'en', MatureContent::Suggestive))
        ->toThrow(ProviderException::class);
})->with([429, 503]);

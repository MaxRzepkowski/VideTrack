<?php

use App\Catalog\ItemNotFoundException;
use App\Catalog\MatureContent;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderBusy;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRegistry;
use App\Catalog\ProviderRejected;
use App\Catalog\Providers\ComicVine\ComicVineClient;
use App\Catalog\Providers\ComicVine\ComicVineProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

function comicVineProvider(): ComicVineProvider
{
    return app(ComicVineProvider::class);
}

test('the comics module does not exist without a ComicVine key', function () {
    expect(app(MediaTypeRegistry::class)->has('comic'))->toBeFalse()
        ->and(fn () => app(ProviderRegistry::class)->get('comicvine'))->toThrow(InvalidArgumentException::class);
});

test('the comic type registers once the ComicVine key is configured', function () {
    enableComicsModule();

    $definition = app(MediaTypeRegistry::class)->get('comic');

    expect($definition->playable)->toBeTrue()
        ->and($definition->searchable)->toBeTrue()
        ->and($definition->isRoot())->toBeTrue()
        ->and($definition->userStatus)->toBeTrue()
        ->and($definition->progressUnit?->value)->toBe('issues')
        ->and($definition->provider)->toBe('comicvine');
});

test('search maps volume summaries and drops coverless hits', function () {
    enableComicsModule();
    Http::fake(['comicvine.gamespot.com/api/search/*' => Http::response(jsonFixture('comicvine/search'))]);

    $results = comicVineProvider()->search('sandman', 'en', MatureContent::Suggestive);

    expect($results)->toHaveCount(2)
        ->and($results[0]->type)->toBe('comic')
        ->and($results[0]->ref)->toBe('4207')
        ->and($results[0]->title)->toBe('The Sandman')
        ->and($results[0]->year)->toBe(1989)
        ->and($results[0]->posterPath)->toBe('https://comicvine.gamespot.com/a/uploads/scale_large/sandman.jpg')
        // ComicVine serves no community ratings.
        ->and($results[0]->communityRating)->toBeNull()
        ->and($results[0]->extra['issues'])->toBe(75)
        ->and($results[0]->progressTotal)->toBe(75)
        ->and($results[0]->extra['publisher'])->toBe('Vertigo');
});

test('volume details map ComicVine fields to the shared shape', function () {
    enableComicsModule();
    Http::fake(['comicvine.gamespot.com/api/volume/4050-4207/*' => Http::response(jsonFixture('comicvine/volume'))]);

    $comic = comicVineProvider()->details('comic', '4207', 'en');

    expect($comic->summary->title)->toBe('The Sandman')
        ->and($comic->summary->year)->toBe(1989)
        ->and($comic->tagline)->toBe('The Lord of Dreams learns one must change or die.')
        // Descriptions are rich HTML — stripped before display.
        ->and($comic->overview)->toBe("Neil Gaiman's magnum opus about the king of stories.")
        ->and($comic->extra['creators'])->toBe(['Neil Gaiman', 'Dave McKean'])
        ->and($comic->extra['publisher'])->toBe('Vertigo')
        ->and($comic->extra['issues'])->toBe(75)
        ->and($comic->summary->progressTotal)->toBe(75)
        ->and($comic->externalLinks['comicvine'])->toBe('https://comicvine.gamespot.com/the-sandman/4050-4207/')
        ->and($comic->posters)->toBe(['https://comicvine.gamespot.com/a/uploads/scale_large/sandman.jpg'])
        // Comics have no runtime — issues are the progress unit, and issue
        // totals must never leak into time statistics via runtime.
        ->and($comic->runtime)->toBeNull();
});

test('unknown volume refs raise the not-found exception', function () {
    enableComicsModule();
    Http::fake(['comicvine.gamespot.com/*' => Http::response(jsonFixture('comicvine/not-found'))]);

    expect(fn () => comicVineProvider()->details('comic', '424242', 'en'))
        ->toThrow(ItemNotFoundException::class);
});

test('a volume ComicVine no longer has is asked about once per not-found window', function () {
    enableComicsModule();
    Http::fake(['comicvine.gamespot.com/*' => Http::response(jsonFixture('comicvine/not-found'))]);

    $ask = fn () => rescue(fn () => comicVineProvider()->details('comic', '424242', 'en'), report: false);

    $ask();
    $ask();
    Http::assertSentCount(1);

    $this->travel(config()->integer('tracker.comicvine.cache.not_found') + 1)->seconds();
    $ask();
    Http::assertSentCount(2);
});

test('a cover prefers the card-sized scale over the print original', function () {
    enableComicsModule();
    $volume = jsonFixture('comicvine/volume');
    $volume['results']['image'] = ['medium_url' => 'https://comicvine.example/medium.jpg', 'original_url' => 'https://comicvine.example/original.jpg'];
    Http::fake(['comicvine.gamespot.com/*' => Http::response($volume)]);

    expect(comicVineProvider()->details('comic', '4207', 'en')->summary->posterPath)->toBe('https://comicvine.example/medium.jpg');
});

test('a wrong key is a refusal, not an outage', function () {
    enableComicsModule();
    Http::fake(['comicvine.gamespot.com/*' => Http::response(['error' => 'Invalid API Key', 'status_code' => 100, 'results' => []])]);

    expect(fn () => comicVineProvider()->search('sandman', 'en', MatureContent::Suggestive))
        ->toThrow(ProviderRejected::class);
});

test('an unknown code surfaces as a provider failure', function () {
    enableComicsModule();
    Http::fake(['comicvine.gamespot.com/*' => Http::response(['error' => 'Nope', 'status_code' => 999, 'results' => []])]);

    expect(fn () => comicVineProvider()->search('sandman', 'en', MatureContent::Suggestive))
        ->toThrow(ProviderException::class);
});

test('the rate limit asks to be waited out for the hour', function () {
    enableComicsModule();
    Http::fake(['comicvine.gamespot.com/*' => Http::response(['error' => 'Rate limit exceeded', 'status_code' => 107, 'results' => []])]);

    try {
        comicVineProvider()->search('sandman', 'en', MatureContent::Suggestive);
        $this->fail('The rate limit went unnoticed.');
    } catch (ProviderBusy $busy) {
        expect($busy->retryAfterSeconds)->toBe(ComicVineClient::BUDGET_WINDOW_SECONDS);
    }
});

test('a spent hourly budget names when the next request is welcome', function () {
    enableComicsModule();
    Http::fake();
    RateLimiter::increment('comicvine-api-hour', ComicVineClient::BUDGET_WINDOW_SECONDS, ComicVineClient::RATE_LIMIT_PER_HOUR);
    $this->travel(20)->minutes();

    try {
        comicVineProvider()->search('sandman', 'en', MatureContent::Suggestive);
        $this->fail('The spent budget went unnoticed.');
    } catch (ProviderBusy $busy) {
        expect($busy->retryAfterSeconds)->toBe(ComicVineClient::BUDGET_WINDOW_SECONDS - 20 * 60);
    }

    Http::assertNothingSent();
});

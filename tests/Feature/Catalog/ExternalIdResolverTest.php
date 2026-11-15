<?php

use App\Catalog\ExternalIdResolver;
use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Catalog\ProviderException;
use App\Models\ExternalIdMapping;
use Illuminate\Support\Facades\Http;

function resolver(): ExternalIdResolver
{
    return app(ExternalIdResolver::class);
}

test('our own provider id resolves without a single request', function () {
    Http::fake();

    $resolved = resolver()->resolve(['tmdb' => '603'], MediaKind::Movie, 'en');

    expect($resolved?->type)->toBe('movie')
        ->and($resolved?->ref)->toBe('603');

    Http::assertNothingSent();
});

test('an identity mapping is not written to the mapping table', function () {
    resolver()->resolve(['tmdb' => '603'], MediaKind::Movie, 'en');

    expect(ExternalIdMapping::query()->count())->toBe(0);
});

test('an imdb id is translated by the provider and remembered', function () {
    Http::fake(['*/find/tt0133093*' => Http::response(['movie_results' => [['id' => 603]]])]);

    $resolved = resolver()->resolve(['imdb' => 'tt0133093'], MediaKind::Movie, 'en');

    expect($resolved?->type)->toBe('movie')
        ->and($resolved?->ref)->toBe('603');

    $mapping = ExternalIdMapping::query()->firstOrFail();

    expect($mapping->source)->toBe(ExternalIdSource::Imdb)
        ->and($mapping->external_ref)->toBe('tt0133093')
        ->and($mapping->media_kind)->toBe(MediaKind::Movie)
        ->and($mapping->provider)->toBe('tmdb')
        ->and($mapping->provider_ref)->toBe('603');
});

test('a remembered mapping answers without asking the provider again', function () {
    ExternalIdMapping::query()->create([
        'source' => 'imdb',
        'external_ref' => 'tt0133093',
        'media_kind' => 'movie',
        'type' => 'movie',
        'provider' => 'tmdb',
        'provider_ref' => '603',
    ]);

    Http::fake();

    expect(resolver()->resolve(['imdb' => 'tt0133093'], MediaKind::Movie, 'en')?->ref)->toBe('603');

    Http::assertNothingSent();
});

test('an id the provider does not know is not asked about twice', function () {
    Http::fake(['*/find/*' => Http::response(['movie_results' => []])]);

    expect(resolver()->resolve(['imdb' => 'tt9999999'], MediaKind::Movie, 'en'))->toBeNull();

    // A fresh resolver: the memo inside ExternalRefLookup is per instance,
    // so this proves the miss cache and not the memo.
    app()->forgetInstance(ExternalIdResolver::class);

    expect(resolver()->resolve(['imdb' => 'tt9999999'], MediaKind::Movie, 'en'))->toBeNull();

    Http::assertSentCount(1);
});

test('an episode descends from its show through the provider ref format', function () {
    Http::fake();

    $resolved = resolver()->resolve([], MediaKind::Episode, 'en', season: 2, episode: 5, seriesExternalIds: ['tmdb' => '1399']);

    expect($resolved?->type)->toBe('episode')
        ->and($resolved?->ref)->toBe('1399:2:5');
});

test('an episode without its numbers cannot be placed', function () {
    expect(resolver()->resolve([], MediaKind::Episode, 'en', seriesExternalIds: ['tmdb' => '1399']))->toBeNull()
        ->and(resolver()->resolve([], MediaKind::Episode, 'en', season: 2, seriesExternalIds: ['tmdb' => '1399']))->toBeNull();
});

test("an episode's own ids are never read as its show's", function () {
    Http::fake();

    // 63056 is an episode on TMDB. Descending from it would land on some
    // other series entirely, so the only honest answer is none.
    expect(resolver()->resolve(['tmdb' => '63056'], MediaKind::Episode, 'en', season: 1, episode: 1))->toBeNull();

    Http::assertNothingSent();
});

test('a manga is its own mal id', function () {
    enableMangaModule();

    $resolved = resolver()->resolve(['mal' => '11'], MediaKind::Manga, 'en');

    expect($resolved?->type)->toBe('manga')
        ->and($resolved?->ref)->toBe('11');
});

test('a mal id resolves to nothing while the manga module is off', function () {
    expect(resolver()->resolve(['mal' => '11'], MediaKind::Manga, 'en'))->toBeNull();
});

test('an isbn resolves through the books provider', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn']);

    $resolved = resolver()->resolve(['isbn' => '978-0-261-10334-4'], MediaKind::Book, 'en');

    expect($resolved?->type)->toBe('book')
        ->and($resolved?->ref)->toBe('2459845');
});

test('an ISBN-10 is asked about as the ISBN-13 the provider knows', function () {
    enableBooksModule();
    fakeHardcover(['ByIsbn' => 'by_isbn']);

    expect(resolver()->resolve(['isbn' => '0-261-10334-4'], MediaKind::Book, 'en')?->ref)->toBe('2459845');

    Http::assertSent(fn ($request) => str_contains((string) $request->body(), '9780261103344'));
});

test('an isbn looked up during a Hardcover outage is not remembered as unknown', function () {
    enableBooksModule();
    $down = true;
    Http::fake([
        'api.hardcover.app/*' => function () use (&$down) {
            return $down ? Http::response('', 503) : Http::response(jsonFixture('hardcover/by_isbn'));
        },
    ]);

    // An outage is not an answer: the caller hears it (a scrobble retries,
    // an import loses this entry)...
    expect(fn () => resolver()->resolve(['isbn' => '978-0-261-10334-4'], MediaKind::Book, 'en'))->toThrow(ProviderException::class);

    // ...but it is not cached as "no such book" for the next day.
    $down = false;

    expect(resolver()->resolve(['isbn' => '978-0-261-10334-4'], MediaKind::Book, 'en')?->ref)->toBe('2459845');
});

test('a foreign id looked up during a TMDB outage is not remembered as unknown', function () {
    $down = true;
    Http::fake([
        '*/find/tt0133093*' => function () use (&$down) {
            return $down ? Http::response('', 503) : Http::response(['movie_results' => [['id' => 603]]]);
        },
    ]);

    expect(fn () => resolver()->resolve(['imdb' => 'tt0133093'], MediaKind::Movie, 'en'))->toThrow(ProviderException::class);

    $down = false;

    expect(resolver()->resolve(['imdb' => 'tt0133093'], MediaKind::Movie, 'en')?->ref)->toBe('603');
});

test('an id the provider answers 404 for is remembered as unknown', function () {
    Http::fake(['*/find/tt0000404*' => Http::response(['status_code' => 34], 404)]);

    expect(resolver()->resolve(['imdb' => 'tt0000404'], MediaKind::Movie, 'en'))->toBeNull();

    app()->forgetInstance(ExternalIdResolver::class);

    expect(resolver()->resolve(['imdb' => 'tt0000404'], MediaKind::Movie, 'en'))->toBeNull();

    Http::assertSentCount(1);
});

test('a source nobody speaks resolves to nothing', function () {
    expect(resolver()->resolve(['nonsense' => 'xyz'], MediaKind::Movie, 'en'))->toBeNull()
        ->and(resolver()->resolve([], MediaKind::Movie, 'en'))->toBeNull()
        ->and(resolver()->resolve(['tmdb' => ''], MediaKind::Movie, 'en'))->toBeNull();
});

test('a type this instance does not serve resolves to nothing', function () {
    // IGDB keys are absent in the test config, so games are not registered.
    expect(resolver()->resolve(['tmdb' => '1020'], MediaKind::Game, 'en'))->toBeNull();
});

test('an id that could leave its place in a provider address is never asked about', function () {
    Http::fake();

    expect(resolver()->resolve(['tmdb' => '603/../account'], MediaKind::Movie, 'en'))->toBeNull()
        ->and(resolver()->resolve(['imdb' => 'tt0133093?api_key=x'], MediaKind::Movie, 'en'))->toBeNull();

    Http::assertNothingSent();
});

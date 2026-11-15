<?php

use App\Catalog\Data\DiscoverFilter;
use App\Catalog\Providers\Tmdb\TmdbProvider;
use Illuminate\Support\Facades\Http;

/**
 * Filtered discovery: what TMDB offers as discover filters, and what a filtered query
 * actually asks TMDB for. The outgoing parameters are the contract here — a
 * wrong one silently returns a plausible but different catalogue.
 */

/** The dictionaries every definition call reaches for, all healthy. */
function fakeTmdbDictionaries(): void
{
    Http::fake([
        '*/genre/movie/list*' => Http::response(['genres' => [
            ['id' => 28, 'name' => 'Action'],
            ['id' => 18, 'name' => 'Drama'],
        ]]),
        '*/genre/tv/list*' => Http::response(['genres' => [['id' => 18, 'name' => 'Drama']]]),
        '*/configuration/languages*' => Http::response([
            ['iso_639_1' => 'pl', 'english_name' => 'Polish', 'name' => 'Polski'],
            ['iso_639_1' => 'en', 'english_name' => 'English', 'name' => 'English'],
        ]),
        '*/configuration/countries*' => Http::response([
            ['iso_3166_1' => 'US', 'english_name' => 'United States'],
            ['iso_3166_1' => 'PL', 'english_name' => 'Poland'],
        ]),
        '*/certification/movie/list*' => Http::response(jsonFixture('tmdb/certifications_movie')),
        '*/certification/tv/list*' => Http::response(jsonFixture('tmdb/certifications_tv')),
        '*/watch/providers/*' => Http::response(['results' => [
            ['provider_id' => 9, 'provider_name' => 'Prime Video', 'display_priority' => 3],
            ['provider_id' => 8, 'provider_name' => 'Netflix', 'display_priority' => 1],
        ]]),
        '*/discover/*' => Http::response(['page' => 1, 'results' => [], 'total_pages' => 1, 'total_results' => 0]),
    ]);
}

/**
 * The query of the last request whose URL contains $needle, so a test can read
 * the parameters TMDB was actually asked with.
 *
 * @return array<string, mixed>
 */
function tmdbSentQuery(string $needle): array
{
    $urls = collect(Http::recorded())
        ->map(fn (array $pair): string => $pair[0]->url())
        ->filter(fn (string $url): bool => str_contains($url, $needle))
        ->values()
        ->all();

    expect($urls)->not->toBeEmpty("no request was sent to [{$needle}]");

    $query = [];

    // Split by hand: parse_str() would rewrite TMDB's dotted parameter names
    // (primary_release_date.gte) into underscores and hide the very thing
    // these tests are about.
    foreach (explode('&', (string) parse_url((string) end($urls), PHP_URL_QUERY)) as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $query[urldecode($key)] = urldecode($value);
    }

    return $query;
}

/** @param  list<DiscoverFilter>  $filters */
function tmdbFilter(array $filters, string $key): DiscoverFilter
{
    foreach ($filters as $filter) {
        if ($filter->key === $key) {
            return $filter;
        }
    }

    throw new RuntimeException("No [{$key}] filter in the definitions.");
}

test('movie filters expose every supported discover axis', function () {
    fakeTmdbDictionaries();

    $filters = app(TmdbProvider::class)->discoverFilters('movie', 'en', 'US');

    expect(array_map(fn (DiscoverFilter $filter): string => $filter->key, $filters))
        ->toBe([
            'genres',
            'exclude_genres',
            'year',
            'runtime',
            'original_language',
            'origin_country',
            'certification',
            'rating',
            'keywords',
            'watch_provider',
            'sort',
        ])
        // The provider's order survives the trip: options are a list, so
        // nothing re-sorts them numerically on the way to the browser.
        ->and(array_map(fn ($option): string => $option->value, tmdbFilter($filters, 'genres')->options))
        ->toBe(['28', '18'])
        ->and(tmdbFilter($filters, 'year')->kind->value)->toBe('range')
        ->and(tmdbFilter($filters, 'runtime')->rangeMax)->toBe(1440)
        ->and(array_map(fn ($option): string => $option->label, tmdbFilter($filters, 'original_language')->options))
        ->toBe(['English', 'Polish'])
        ->and(array_map(fn ($option): string => $option->value, tmdbFilter($filters, 'origin_country')->options))
        ->toBe(['PL', 'US'])
        ->and(array_map(fn ($option): string => $option->value, tmdbFilter($filters, 'certification')->options))
        ->toBe(['PG-13', 'R'])
        ->and(tmdbFilter($filters, 'keywords')->open)->toBeTrue()
        ->and(tmdbFilter($filters, 'sort')->default)->toBe('popularity.desc')
        ->and(array_map(fn ($option): string => $option->value, tmdbFilter($filters, 'sort')->options))
        ->toBe(['popularity.desc', 'vote_average.desc', 'primary_release_date.desc', 'revenue.desc'])
        // The release date is one axis with two runs, not two entries.
        ->and(array_map(fn ($option): ?string => $option->ascending, tmdbFilter($filters, 'sort')->options))
        ->toBe([null, null, 'primary_release_date.asc', null]);
});

test('only a show gets the status filter, with the five TMDB states', function () {
    fakeTmdbDictionaries();

    $filters = app(TmdbProvider::class)->discoverFilters('show', 'en', 'US');

    expect(array_map(fn (DiscoverFilter $filter): string => $filter->key, $filters))
        ->toBe([
            'genres',
            'exclude_genres',
            'year',
            'runtime',
            'original_language',
            'origin_country',
            'certification',
            'rating',
            'status',
            'show_type',
            'keywords',
            'watch_provider',
            'sort',
        ])
        // 0..4 — "Pilot" (5) is deliberately not offered.
        ->and(array_map(fn ($option): string => $option->value, tmdbFilter($filters, 'status')->options))
        ->toBe(['0', '1', '2', '3', '4'])
        ->and(array_map(fn ($option): string => $option->label, tmdbFilter($filters, 'status')->options))
        ->toBe(['Returning series', 'Planned', 'In production', 'Ended', 'Canceled'])
        ->and(array_map(fn ($option): string => $option->value, tmdbFilter($filters, 'show_type')->options))
        ->toBe(['0', '1', '2', '3', '4', '5', '6'])
        ->and(array_map(fn ($option): string => $option->value, tmdbFilter($filters, 'sort')->options))
        ->toBe(['popularity.desc', 'vote_average.desc', 'first_air_date.desc'])
        ->and(array_map(fn ($option): ?string => $option->ascending, tmdbFilter($filters, 'sort')->options))
        ->toBe([null, null, 'first_air_date.asc']);
});

test('streaming services come in display order for the given region', function () {
    fakeTmdbDictionaries();

    $filters = app(TmdbProvider::class)->discoverFilters('show', 'en', 'PL');

    expect(array_map(fn ($option): string => $option->label, tmdbFilter($filters, 'watch_provider')->options))
        ->toBe(['Netflix', 'Prime Video'])
        ->and(tmdbSentQuery('watch/providers/tv')['watch_region'])->toBe('PL');
});

test('the streaming list is capped, so the region tail never floods the sidebar', function () {
    Http::fake([
        '*/genre/movie/list*' => Http::response(['genres' => []]),
        '*/watch/providers/*' => Http::response(['results' => array_map(
            fn (int $i): array => ['provider_id' => $i, 'provider_name' => "Service {$i}", 'display_priority' => $i],
            range(1, 50),
        )]),
        ...providerDictionaryFakes(),
    ]);

    $filters = app(TmdbProvider::class)->discoverFilters('movie', 'en', 'US');

    expect(tmdbFilter($filters, 'watch_provider')->options)->toHaveCount(30)
        ->and(tmdbFilter($filters, 'watch_provider')->unavailable)->toBeFalse();
});

test('a dead streaming dictionary costs its own filter, not the rest', function () {
    Http::fake([
        '*/genre/movie/list*' => Http::response(['genres' => [['id' => 28, 'name' => 'Action']]]),
        '*/watch/providers/*' => Http::response([], 500),
        ...providerDictionaryFakes(),
    ]);

    $filters = app(TmdbProvider::class)->discoverFilters('movie', 'en', 'US');

    expect(array_map(fn (DiscoverFilter $filter): string => $filter->key, $filters))
        ->toContain('genres', 'year', 'watch_provider', 'sort')
        ->and(tmdbFilter($filters, 'watch_provider')->unavailable)->toBeTrue()
        ->and(tmdbFilter($filters, 'watch_provider')->options)->toBe([])
        ->and(tmdbFilter($filters, 'genres')->options)->toHaveCount(1);
});

test('a dead genre dictionary costs its own filter, not the rest', function () {
    Http::fake([
        '*/genre/movie/list*' => Http::response([], 500),
        '*/watch/providers/*' => Http::response(['results' => [
            ['provider_id' => 8, 'provider_name' => 'Netflix', 'display_priority' => 1],
        ]]),
        ...providerDictionaryFakes(),
    ]);

    $filters = app(TmdbProvider::class)->discoverFilters('movie', 'en', 'US');

    expect(tmdbFilter($filters, 'genres')->unavailable)->toBeTrue()
        ->and(tmdbFilter($filters, 'genres')->options)->toBe([])
        ->and(tmdbFilter($filters, 'watch_provider')->options)->toHaveCount(1);
});

test('several genres are asked for as an OR, not as an intersection', function () {
    fakeTmdbDictionaries();

    app(TmdbProvider::class)->discoverFiltered('movie', ['genres' => ['28', '18']], 'en', 'US');

    $query = tmdbSentQuery('discover/movie');

    expect($query['with_genres'])->toBe('28|18')
        ->and($query['include_adult'])->toBe('0')
        ->and($query['page'])->toBe('1')
        ->and($query['sort_by'])->toBe('popularity.desc')
        ->and($query)->not->toHaveKey('vote_count.gte');
});

test('the new filters map to the exact TMDB parameters', function () {
    fakeTmdbDictionaries();

    app(TmdbProvider::class)->discoverFiltered('show', [
        'exclude_genres' => ['18', '35'],
        'runtime' => ['from' => 25, 'to' => 60],
        'original_language' => 'pl',
        'origin_country' => 'PL',
        'certification' => '18',
        'rating' => '7',
        'show_type' => '2',
        'keywords' => ['6091', '9715'],
    ], 'en', 'PL');

    expect(tmdbSentQuery('discover/tv'))->toMatchArray([
        'without_genres' => '18|35',
        'with_runtime.gte' => '25',
        'with_runtime.lte' => '60',
        'with_original_language' => 'pl',
        'with_origin_country' => 'PL',
        'certification' => '18',
        'certification_country' => 'PL',
        'vote_average.gte' => '7',
        'with_type' => '2',
        'with_keywords' => '6091|9715',
    ]);
});

test('certification is never sent without its country', function () {
    fakeTmdbDictionaries();

    app(TmdbProvider::class)->discoverFiltered('movie', ['certification' => 'R'], 'en', '');

    expect(tmdbSentQuery('discover/movie'))
        ->not->toHaveKey('certification')
        ->not->toHaveKey('certification_country');
});

test('keyword refs from the url are named and remain removable', function () {
    Http::fake([
        '*/keyword/9715*' => Http::response(['id' => 9715, 'name' => 'superhero']),
        '*' => Http::response([]),
    ]);

    $filter = tmdbFilter(app(TmdbProvider::class)->discoverFilters('movie', 'en', 'US', ['keywords' => ['9715']]), 'keywords');

    expect($filter->open)->toBeTrue()
        ->and($filter->options)->toHaveCount(1)
        ->and($filter->options[0]->value)->toBe('9715')
        ->and($filter->options[0]->label)->toBe('superhero');
});

test('dead configuration dictionaries disable only their own filters', function () {
    Http::fake([
        '*/genre/movie/list*' => Http::response(['genres' => [['id' => 28, 'name' => 'Action']]]),
        '*/configuration/languages*' => Http::response([]),
        '*/configuration/countries*' => Http::response([]),
        '*/certification/movie/list*' => Http::response(jsonFixture('tmdb/certifications_movie')),
        '*/watch/providers/*' => Http::response(['results' => []]),
    ]);

    $filters = app(TmdbProvider::class)->discoverFilters('movie', 'en', 'US');

    expect(tmdbFilter($filters, 'original_language')->unavailable)->toBeTrue()
        ->and(tmdbFilter($filters, 'origin_country')->unavailable)->toBeTrue()
        ->and(tmdbFilter($filters, 'genres')->unavailable)->toBeFalse()
        ->and(tmdbFilter($filters, 'certification')->unavailable)->toBeFalse();
});

test('a year span asks each type for its own date field', function () {
    fakeTmdbDictionaries();

    app(TmdbProvider::class)->discoverFiltered('movie', ['year' => ['from' => 1999, 'to' => 2005]], 'en', 'US');

    expect(tmdbSentQuery('discover/movie'))
        // The film's own debut — a 2027 re-release of a 1999 film must not
        // drag it into a 2027 query.
        ->toMatchArray(['primary_release_date.gte' => '1999-01-01', 'primary_release_date.lte' => '2005-12-31']);

    app(TmdbProvider::class)->discoverFiltered('show', ['year' => ['from' => 1999, 'to' => null]], 'en', 'US');

    $query = tmdbSentQuery('discover/tv');

    expect($query['first_air_date.gte'])->toBe('1999-01-01')
        ->and($query)->not->toHaveKey('first_air_date.lte');
});

test('a value the filter does not offer never reaches TMDB', function () {
    fakeTmdbDictionaries();

    app(TmdbProvider::class)->discoverFiltered('show', [
        'certification' => 'XXX',
        'rating' => '9',
        'status' => '5',
        'show_type' => '7',
        'watch_provider' => 'netflix',
        'sort' => 'revenue.desc',
    ], 'en', 'PL');

    expect(tmdbSentQuery('discover/tv'))
        ->not->toHaveKeys(['certification', 'vote_average.gte', 'with_status', 'with_type', 'with_watch_providers'])
        ->toMatchArray(['sort_by' => 'popularity.desc']);
});

test('show status is asked for only where it exists', function () {
    fakeTmdbDictionaries();

    app(TmdbProvider::class)->discoverFiltered('show', ['status' => '0'], 'en', 'US');
    expect(tmdbSentQuery('discover/tv')['with_status'])->toBe('0');

    // A film has no status filter, so a hand-typed one must not leak through.
    app(TmdbProvider::class)->discoverFiltered('movie', ['status' => '0'], 'en', 'US');
    expect(tmdbSentQuery('discover/movie'))->not->toHaveKey('with_status');
});

test('a streaming service means "in my subscription"', function () {
    fakeTmdbDictionaries();

    app(TmdbProvider::class)->discoverFiltered('movie', ['watch_provider' => '8'], 'en', 'PL');

    // Without the monetization type, Prime Video answers with its whole
    // rent-and-buy catalogue and the filter means nothing.
    expect(tmdbSentQuery('discover/movie'))->toMatchArray([
        'with_watch_providers' => '8',
        'watch_region' => 'PL',
        'with_watch_monetization_types' => 'flatrate',
    ]);
});

test('sorting by score carries a vote floor, other sorts do not', function () {
    fakeTmdbDictionaries();

    app(TmdbProvider::class)->discoverFiltered('movie', ['sort' => 'vote_average.desc'], 'en', 'US');

    expect(tmdbSentQuery('discover/movie'))
        ->toMatchArray(['sort_by' => 'vote_average.desc', 'vote_count.gte' => '200']);

    app(TmdbProvider::class)->discoverFiltered('movie', ['sort' => 'revenue.desc'], 'en', 'US');

    $query = tmdbSentQuery('discover/movie');

    expect($query['sort_by'])->toBe('revenue.desc')
        ->and($query)->not->toHaveKey('vote_count.gte');
});

test('a sort outside the whitelist falls back to the default', function () {
    fakeTmdbDictionaries();

    // Neither a raw TMDB expression nor another type's sort gets through.
    app(TmdbProvider::class)->discoverFiltered('show', ['sort' => 'revenue.desc'], 'en', 'US');

    expect(tmdbSentQuery('discover/tv')['sort_by'])->toBe('popularity.desc');
});

test('the ascending run of a directional sort is whitelisted too', function () {
    fakeTmdbDictionaries();

    // It is offered by no option's `value`, only as one option's twin.
    app(TmdbProvider::class)->discoverFiltered('show', ['sort' => 'first_air_date.asc'], 'en', 'US');

    expect(tmdbSentQuery('discover/tv')['sort_by'])->toBe('first_air_date.asc');
});

test('the page is clamped and the page count capped to TMDB\'s ceiling', function () {
    Http::fake(['*/discover/*' => Http::response([
        'page' => 500,
        'results' => [],
        'total_pages' => 743,
        'total_results' => 14860,
    ])]);

    $listing = app(TmdbProvider::class)->discoverFiltered('movie', [], 'en', 'US', 900);

    expect(tmdbSentQuery('discover/movie')['page'])->toBe('500')
        ->and($listing->page)->toBe(500)
        ->and($listing->totalPages)->toBe(500)
        // The count is the real one — only the reachable pages are capped.
        ->and($listing->total)->toBe(14860);
});

test('the filtered grid keeps the shared cover policy', function () {
    Http::fake(['*/discover/*' => Http::response([
        'page' => 1,
        'results' => [
            ['id' => 603, 'title' => 'The Matrix', 'poster_path' => '/m.jpg', 'release_date' => '1999-03-30'],
            ['id' => 604, 'title' => 'No Poster', 'release_date' => '1999-03-30'],
        ],
        'total_pages' => 1,
        'total_results' => 2,
    ])]);

    $listing = app(TmdbProvider::class)->discoverFiltered('movie', [], 'en', 'US');

    // summaries() is shared with collection parts and similar titles, so the
    // posterless entry is dropped here too — deliberately.
    expect($listing->items)->toHaveCount(1)
        ->and($listing->items[0]->title)->toBe('The Matrix');
});

test('an honest empty result is cached, so a dead-end filter is not re-asked', function () {
    Http::fake(['*/discover/*' => Http::response([
        'page' => 1,
        'results' => [],
        'total_pages' => 1,
        'total_results' => 0,
    ])]);

    app(TmdbProvider::class)->discoverFiltered('movie', ['genres' => ['28', '18']], 'en', 'US');
    app(TmdbProvider::class)->discoverFiltered('movie', ['genres' => ['28', '18']], 'en', 'US');

    Http::assertSentCount(1);
});

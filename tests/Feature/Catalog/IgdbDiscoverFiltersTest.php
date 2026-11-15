<?php

use App\Catalog\Data\DiscoverFilter;
use App\Catalog\DiscoverFilterKind;
use App\Catalog\Providers\Igdb\IgdbProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Filtered discovery on Apicalypse: what IGDB offers as discover filters, and what the
 * `where` clause of a filtered query actually says. Everything here travels in
 * a POST body, so the body is the contract.
 */

/** The dictionaries and the games endpoint, all healthy. */
function fakeIgdbFilterEndpoints(): void
{
    enableGamesModule();

    Http::fake([
        'id.twitch.tv/*' => Http::response(['access_token' => 'testing-token', 'expires_in' => 5000000]),
        '*/genres' => Http::response([
            ['id' => 31, 'name' => 'Adventure'],
            ['id' => 12, 'name' => 'Role-playing (RPG)'],
        ]),
        '*/platforms' => Http::response([
            ['id' => 6, 'name' => 'PC (Microsoft Windows)'],
            ['id' => 48, 'name' => 'PlayStation 4'],
        ]),
        '*/languages' => Http::response([
            ['id' => 7, 'name' => 'English', 'locale' => 'en-US'],
            ['id' => 16, 'name' => 'Polish', 'locale' => 'pl-PL'],
            ['id' => 99, 'name' => 'Klingon'],
        ]),
        '*/game_modes' => Http::response([
            ['id' => 1, 'name' => 'Single player'],
            ['id' => 3, 'name' => 'Co-operative'],
        ]),
        '*/player_perspectives' => Http::response([
            ['id' => 2, 'name' => 'Third person'],
            ['id' => 1, 'name' => 'First person'],
        ]),
        '*/games/count' => Http::response(['count' => 42]),
        '*/games' => Http::response([]),
    ]);
}

/**
 * The body of the last POST to $endpoint — the Apicalypse query IGDB was
 * actually asked.
 */
function igdbSentBody(string $endpoint): string
{
    $bodies = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH) ?? '', $endpoint))
        ->map(fn (array $pair): string => $pair[0]->body())
        ->values()
        ->all();

    expect($bodies)->not->toBeEmpty("no request was sent to [{$endpoint}]");

    return (string) end($bodies);
}

/** @param  list<DiscoverFilter>  $filters */
function igdbFilter(array $filters, string $key): DiscoverFilter
{
    foreach ($filters as $filter) {
        if ($filter->key === $key) {
            return $filter;
        }
    }

    throw new RuntimeException("No [{$key}] filter in the definitions.");
}

test('game filters are genres, platform, years and sort', function () {
    // Five dictionaries, more than the limiter's window lets a frozen clock pass.
    $this->travelBack();
    fakeIgdbFilterEndpoints();

    $filters = app(IgdbProvider::class)->discoverFilters('game', 'en', 'US');

    expect(array_map(fn (DiscoverFilter $filter): string => $filter->key, $filters))
        ->toBe(['genres', 'platform', 'language', 'modes', 'perspective', 'year', 'sort'])
        ->and(array_map(fn ($option): string => $option->label, igdbFilter($filters, 'platform')->options))
        ->toBe(['PC (Microsoft Windows)', 'PlayStation 4'])
        ->and(array_map(fn ($option): string => $option->value, igdbFilter($filters, 'genres')->options))
        ->toBe(['31', '12'])
        ->and(igdbFilter($filters, 'sort')->default)->toBe('total_rating_count desc')
        ->and(array_map(fn ($option): string => $option->value, igdbFilter($filters, 'sort')->options))
        ->toBe(['total_rating_count desc', 'total_rating desc', 'hypes desc', 'first_release_date desc'])
        // The release date is one axis with two runs, not two entries.
        ->and(array_map(fn ($option): ?string => $option->ascending, igdbFilter($filters, 'sort')->options))
        ->toBe([null, null, null, 'first_release_date asc']);
});

test("languages are named in the reader's language, the rest of the play filters as IGDB words them", function () {
    // Five dictionaries, more than the limiter's window lets a frozen clock pass.
    $this->travelBack();
    fakeIgdbFilterEndpoints();

    $filters = app(IgdbProvider::class)->discoverFilters('game', 'pl', 'PL');
    $labels = fn (string $key): array => array_map(fn ($option): string => $option->label, igdbFilter($filters, $key)->options);

    expect($labels('language'))->toBe(['angielski', 'Klingon', 'polski'])
        ->and(igdbFilter($filters, 'language')->kind)->toBe(DiscoverFilterKind::Select)
        ->and($labels('modes'))->toBe(['Co-operative', 'Single player'])
        ->and($labels('perspective'))->toBe(['First person', 'Third person'])
        ->and(igdbFilter($filters, 'perspective')->kind)->toBe(DiscoverFilterKind::MultiSelect);
});

test('platforms are fetched in one alphabetical page', function () {
    fakeIgdbFilterEndpoints();

    app(IgdbProvider::class)->discoverFilters('game', 'en', 'US');

    // 500 is Apicalypse's ceiling and more than the whole catalogue, so the
    // sidebar never shows a truncated platform list.
    expect(igdbSentBody('/platforms'))->toBe('fields name; sort name asc; limit 500;');
});

test('a dead platform dictionary costs its own filter, not the rest', function () {
    // Five dictionaries, more than the limiter's window lets a frozen clock pass.
    $this->travelBack();
    enableGamesModule();

    Http::fake([
        'id.twitch.tv/*' => Http::response(['access_token' => 'testing-token', 'expires_in' => 5000000]),
        '*/genres' => Http::response([['id' => 31, 'name' => 'Adventure']]),
        '*/platforms' => Http::response([], 500),
        ...providerDictionaryFakes(),
    ]);

    $filters = app(IgdbProvider::class)->discoverFilters('game', 'en', 'US');

    expect(array_map(fn (DiscoverFilter $filter): string => $filter->key, $filters))
        ->toBe(['genres', 'platform', 'language', 'modes', 'perspective', 'year', 'sort'])
        ->and(igdbFilter($filters, 'platform')->unavailable)->toBeTrue()
        ->and(igdbFilter($filters, 'platform')->options)->toBe([])
        ->and(igdbFilter($filters, 'genres')->options)->toHaveCount(1);
});

test('a dead genre dictionary costs its own filter, not the rest', function () {
    // Five dictionaries, more than the limiter's window lets a frozen clock pass.
    $this->travelBack();
    enableGamesModule();

    Http::fake([
        'id.twitch.tv/*' => Http::response(['access_token' => 'testing-token', 'expires_in' => 5000000]),
        '*/genres' => Http::response([], 500),
        '*/platforms' => Http::response([['id' => 6, 'name' => 'PC (Microsoft Windows)']]),
        ...providerDictionaryFakes(),
    ]);

    $filters = app(IgdbProvider::class)->discoverFilters('game', 'en', 'US');

    expect(igdbFilter($filters, 'genres')->unavailable)->toBeTrue()
        ->and(igdbFilter($filters, 'platform')->options)->toHaveCount(1);
});

test('the where clause carries every filter and the listing is paged by offset', function () {
    fakeIgdbFilterEndpoints();

    app(IgdbProvider::class)->discoverFiltered('game', [
        'genres' => ['31', '12'],
        'platform' => '6',
        'language' => '16',
        'modes' => ['3', '1'],
        'perspective' => ['2', 'DROP TABLE'],
        'year' => ['from' => 2015, 'to' => 2016],
    ], 'en', 'US', 3);

    $body = igdbSentBody('/games');

    expect($body)->toContain('genres = (31,12)')
        ->toContain('platforms = (6)')
        ->toContain('language_supports.language = (16)')
        ->toContain('game_modes = (3,1)')
        // Nothing but digits reaches the query.
        ->toContain('player_perspectives = (2)')
        // 2015-01-01 and 2017-01-01 UTC: the upper end is exclusive, so all
        // of 2016 stays inside the span.
        ->toContain('first_release_date >= 1420070400')
        ->toContain('first_release_date < 1483228800')
        ->toContain('cover != null')
        ->toContain('game_type = (0,1,2,4,8,9,10)')
        ->toContain('limit 20; offset 40;');
});

test('the quality floor belongs to the rating sorts only', function () {
    // More requests than the provider allows per second: its rate limiter
    // waits for the window to pass, which a frozen clock never lets happen.
    $this->travelBack();

    fakeIgdbFilterEndpoints();

    app(IgdbProvider::class)->discoverFiltered('game', ['sort' => 'total_rating desc'], 'en', 'US');
    expect(igdbSentBody('/games'))
        ->toContain('total_rating_count > 5')
        ->toContain('sort total_rating desc;');

    // On "newest first" the floor would hide exactly what is being asked for.
    app(IgdbProvider::class)->discoverFiltered('game', ['sort' => 'first_release_date desc'], 'en', 'US');
    expect(igdbSentBody('/games'))
        ->not->toContain('total_rating_count >')
        ->toContain('sort first_release_date desc;');
});

test('the most anticipated are the games still to come, dated or not', function () {
    fakeIgdbFilterEndpoints();

    app(IgdbProvider::class)->discoverFiltered('game', ['sort' => 'hypes desc'], 'en', 'US');

    $startOfToday = CarbonImmutable::now('UTC')->startOfDay()->getTimestamp();

    expect(igdbSentBody('/games'))
        ->toContain("(first_release_date >= {$startOfToday} | first_release_date = null) & hypes > 0")
        ->not->toContain('total_rating_count >')
        ->toContain('sort hypes desc;');
});

test('a sort outside the whitelist falls back to the default', function () {
    fakeIgdbFilterEndpoints();

    app(IgdbProvider::class)->discoverFiltered('game', ['sort' => 'name desc; drop'], 'en', 'US');

    expect(igdbSentBody('/games'))->toContain('sort total_rating_count desc;');
});

test('the ascending run of a directional sort is whitelisted too', function () {
    fakeIgdbFilterEndpoints();

    // It is offered by no option's `value`, only as one option's twin.
    app(IgdbProvider::class)->discoverFiltered('game', ['sort' => 'first_release_date asc'], 'en', 'US');

    expect(igdbSentBody('/games'))->toContain('sort first_release_date asc;');
});

test('non-numeric ids never reach the query', function () {
    fakeIgdbFilterEndpoints();

    app(IgdbProvider::class)->discoverFiltered('game', [
        'genres' => ['31', '1); drop'],
        'platform' => 'six',
    ], 'en', 'US');

    expect(igdbSentBody('/games'))
        ->toContain('genres = (31)')
        ->not->toContain('platforms =');
});

test('the total comes from the count sub-resource over the same where clause', function () {
    fakeIgdbFilterEndpoints();

    $listing = app(IgdbProvider::class)->discoverFiltered('game', ['genres' => ['31']], 'en', 'US', 2);

    expect($listing->total)->toBe(42)
        // 42 rows, 20 per page.
        ->and($listing->totalPages)->toBe(3)
        ->and($listing->page)->toBe(2)
        ->and(igdbSentBody('/games/count'))->toContain('genres = (31)');
});

test('an honest empty result is cached, so a dead-end filter is not re-asked', function () {
    fakeIgdbFilterEndpoints();

    app(IgdbProvider::class)->discoverFiltered('game', ['genres' => ['31']], 'en', 'US');
    $first = count(Http::recorded());

    app(IgdbProvider::class)->discoverFiltered('game', ['genres' => ['31']], 'en', 'US');

    expect(count(Http::recorded()))->toBe($first);
});

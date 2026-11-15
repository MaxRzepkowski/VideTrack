<?php

use App\Actions\Media\NormalizeDiscoverFilters;
use App\Catalog\Data\DiscoverFilter;
use App\Catalog\Data\DiscoverFilterOption;
use App\Catalog\DiscoverFilterKind;

/**
 * The definitions a TMDB show page would hand over: a multi-select whose
 * options are deliberately NOT in numeric order (so re-ordering is visible), a
 * select whose first value is the string "0", a year range and a sort with a
 * resting value.
 *
 * @return list<DiscoverFilter>
 */
function discoverDefinitions(bool $unavailableWatchProvider = false): array
{
    return [
        new DiscoverFilter('genres', 'Genres', DiscoverFilterKind::MultiSelect, [
            new DiscoverFilterOption('18', 'Drama'),
            new DiscoverFilterOption('10765', 'Sci-Fi & Fantasy'),
            new DiscoverFilterOption('35', 'Comedy'),
        ]),
        new DiscoverFilter('year', 'Year', DiscoverFilterKind::Range, rangeMin: 1900, rangeMax: now()->year + 5),
        new DiscoverFilter('runtime', 'Runtime', DiscoverFilterKind::Range, rangeMin: 0, rangeMax: 1440),
        new DiscoverFilter('status', 'Show status', DiscoverFilterKind::Select, [
            new DiscoverFilterOption('0', 'Returning Series'),
            new DiscoverFilterOption('3', 'Ended'),
        ]),
        new DiscoverFilter(
            'watch_provider',
            'Streaming service',
            DiscoverFilterKind::Select,
            $unavailableWatchProvider ? [] : [new DiscoverFilterOption('8', 'Netflix')],
            unavailable: $unavailableWatchProvider,
        ),
        new DiscoverFilter('sort', 'Sort by', DiscoverFilterKind::Select, [
            new DiscoverFilterOption('popularity.desc', 'Popularity'),
            new DiscoverFilterOption('vote_average.desc', 'Highest rated'),
        ], default: 'popularity.desc'),
    ];
}

function normalizeDiscover(mixed $raw, bool $unavailableWatchProvider = false): array
{
    return (new NormalizeDiscoverFilters)->execute(discoverDefinitions($unavailableWatchProvider), $raw);
}

test('a non-array f parameter filters nothing', function () {
    expect(normalizeDiscover('x'))->toBe([])
        ->and(normalizeDiscover(null))->toBe([])
        ->and(normalizeDiscover([]))->toBe([]);
});

test('keys outside the definitions are dropped', function () {
    expect(normalizeDiscover(['runtime' => '90', 'status' => '3']))->toBe(['status' => '3']);
});

test('a select value that is not a string is dropped', function () {
    expect(normalizeDiscover(['status' => ['3']]))->toBe([]);
});

test('a select value outside the options is dropped', function () {
    expect(normalizeDiscover(['status' => '9']))->toBe([]);
});

test('the select value "0" survives', function () {
    expect(normalizeDiscover(['status' => '0']))->toBe(['status' => '0']);
});

test('a select value equal to the default is dropped', function () {
    expect(normalizeDiscover(['sort' => 'popularity.desc']))->toBe([])
        ->and(normalizeDiscover(['sort' => 'vote_average.desc']))->toBe(['sort' => 'vote_average.desc']);
});

test('a single multi-select string is wrapped into a list', function () {
    expect(normalizeDiscover(['genres' => '35']))->toBe(['genres' => ['35']]);
});

test('non-string and nested multi-select entries are dropped', function () {
    expect(normalizeDiscover(['genres' => [['18'], 35, null, '35']]))->toBe(['genres' => ['35']]);
});

test('multi-select duplicates collapse', function () {
    expect(normalizeDiscover(['genres' => ['18', '18']]))->toBe(['genres' => ['18']]);
});

test('multi-select values are re-ordered to the provider order', function () {
    expect(normalizeDiscover(['genres' => ['35', '18', '10765']]))
        ->toBe(['genres' => ['18', '10765', '35']]);
});

test('a multi-select left empty by the sieve drops its key', function () {
    expect(normalizeDiscover(['genres' => ['999', '']]))->toBe([]);
});

test('year range ends are read from two separate keys', function () {
    expect(normalizeDiscover(['year_from' => '1999', 'year_to' => '2005']))
        ->toBe(['year' => ['from' => 1999, 'to' => 2005]]);
});

test('one end of a year range stands alone', function () {
    expect(normalizeDiscover(['year_from' => '1999']))->toBe(['year' => ['from' => 1999, 'to' => null]])
        ->and(normalizeDiscover(['year_to' => '2005']))->toBe(['year' => ['from' => null, 'to' => 2005]]);
});

test('a backwards year range is swapped', function () {
    expect(normalizeDiscover(['year_from' => '2005', 'year_to' => '1999']))
        ->toBe(['year' => ['from' => 1999, 'to' => 2005]]);
});

test('year range ends are clamped to the offered span', function () {
    $ahead = now()->year + 5;

    expect(normalizeDiscover(['year_from' => '1200', 'year_to' => '9999']))
        ->toBe(['year' => ['from' => 1900, 'to' => $ahead]]);
});

test('a number cut short of the minimum is dropped, not clamped up to it', function () {
    expect(normalizeDiscover(['year_from' => '199']))->toBe([])
        // A span starting at zero has no such thing as too short.
        ->and(normalizeDiscover(['runtime_from' => '0', 'runtime_to' => '90']))
        ->toBe(['runtime' => ['from' => 0, 'to' => 90]]);
});

test('non-digit year range ends are dropped', function () {
    expect(normalizeDiscover(['year_from' => 'abc', 'year_to' => '20']))->toBe([])
        ->and(normalizeDiscover(['year_from' => ['1999'], 'year_to' => '2005']))
        ->toBe(['year' => ['from' => null, 'to' => 2005]]);
});

test('a value for an unavailable filter is kept so the caller can degrade', function () {
    expect(normalizeDiscover(['watch_provider' => '8'], unavailableWatchProvider: true))
        ->toBe(['watch_provider' => '8']);
});

test('an open multi-select accepts numeric provider refs outside its current options', function () {
    $definitions = [new DiscoverFilter(
        'keywords',
        'Keyword',
        DiscoverFilterKind::MultiSelect,
        [new DiscoverFilterOption('9715', 'superhero')],
        open: true,
    )];

    $normalize = new NormalizeDiscoverFilters;

    expect($normalize->execute($definitions, ['keywords' => ['999', '9715', 'not-an-id']]))
        ->toBe(['keywords' => ['9715', '999']]);
});

test('a numeric range uses the bounds supplied by its definition', function () {
    $definitions = [new DiscoverFilter('runtime', 'Runtime', DiscoverFilterKind::Range, rangeMin: 0, rangeMax: 1440)];

    expect((new NormalizeDiscoverFilters)->execute($definitions, ['runtime_from' => '90', 'runtime_to' => '9999']))
        ->toBe(['runtime' => ['from' => 90, 'to' => 1440]]);
});

test('filters come back in definition order, not query order', function () {
    $filters = normalizeDiscover([
        'sort' => 'vote_average.desc',
        'status' => '3',
        'year_from' => '1999',
        'genres' => ['18'],
    ]);

    expect(array_keys($filters))->toBe(['genres', 'year', 'status', 'sort']);
});

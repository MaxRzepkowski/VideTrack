<?php

use App\Catalog\Providers\ComicVine\ComicVineProvider;
use App\Support\ExternalServiceException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// The shape is ComicVine's documented list answer: `status_code` 1, the rows
// under `results`, at most a hundred a page, paged by `offset`. A volume row
// asked for its `id` carries it; an issue row asked for its `volume` carries
// the volume as an object.

/**
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function comicVineList(array $rows, int $offset = 0): array
{
    return [
        'error' => 'OK',
        'limit' => 100,
        'offset' => $offset,
        'number_of_page_results' => count($rows),
        'number_of_total_results' => count($rows) + $offset,
        'status_code' => 1,
        'results' => $rows,
        'version' => '1.0',
    ];
}

/**
 * @param  list<int>  $ids
 * @return list<array<string, mixed>>
 */
function volumeRows(array $ids): array
{
    return array_map(fn (int $id): array => ['id' => $id], $ids);
}

/**
 * @param  list<int>  $volumes
 * @return list<array<string, mixed>>
 */
function issueRows(array $volumes): array
{
    return array_map(fn (int $id): array => ['volume' => ['api_detail_url' => "https://comicvine.gamespot.com/api/volume/4050-{$id}/", 'id' => $id, 'name' => 'Volume', 'site_detail_url' => "https://comicvine.gamespot.com/volume/4050-{$id}/"]], $volumes);
}

function comicVineChanges(): ComicVineProvider
{
    enableComicsModule();

    return app(ComicVineProvider::class);
}

/** A sent request's query parameter. */
function sentParameter(Request $request, string $key): string
{
    $value = $request->data()[$key] ?? '';

    return is_scalar($value) ? (string) $value : '';
}

test('the window reaches from a day before the last reading to a day ahead', function () {
    $provider = comicVineChanges();
    Http::fake(['comicvine.gamespot.com/api/volumes/*' => Http::response(comicVineList([]))]);

    // One volume buys one page, so only the first list is read.
    $provider->changedAmong('comic', ['4207'], CarbonImmutable::now()->subDay());

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => sentParameter($request, 'filter') === 'date_last_updated:2026-10-08 12:00:00|2026-10-11 12:00:00'
        && sentParameter($request, 'field_list') === 'id'
        && sentParameter($request, 'sort') === 'id:asc');
});

test('our volumes edited and our volumes with a new issue', function () {
    // Each list is a request at the client's pace of one a second.
    $this->travelBack();
    $provider = comicVineChanges();
    Http::fake([
        'comicvine.gamespot.com/api/volumes/*' => Http::response(comicVineList(volumeRows([4207, 5]))),
        'comicvine.gamespot.com/api/issues/*' => Http::response(comicVineList(issueRows([18166, 18166, 6]))),
    ]);

    $report = $provider->changedAmong('comic', ['4207', '18166', '796'], CarbonImmutable::now()->subDay());

    expect($report->isKnown())->toBeTrue()
        ->and($report->refs)->toBe(['4207', '18166']);

    [$volumes, $issues] = Http::recorded()->map(fn (array $pair): Request => $pair[0])->all();
    expect(sentParameter($issues, 'filter'))->toBe('date_added:'.substr(sentParameter($volumes, 'filter'), strlen('date_last_updated:')))
        ->and(sentParameter($issues, 'field_list'))->toBe('volume');
});

test('a full page has another behind it', function () {
    $this->travelBack();
    $provider = comicVineChanges();
    Http::fake([
        'comicvine.gamespot.com/api/volumes/*' => Http::response(comicVineList([])),
        'comicvine.gamespot.com/api/issues/*' => fn (Request $request) => Http::response(
            sentParameter($request, 'offset') === '0' ? comicVineList(issueRows([4207, ...range(1, 99)])) : comicVineList(issueRows([18166]), 100),
        ),
    ]);

    expect($provider->changedAmong('comic', ['4207', '18166', '796'], CarbonImmutable::now()->subDay())->refs)->toBe(['4207', '18166']);
    Http::assertSentCount(3);
});

test('lists longer in pages than our volumes are many are unknown: asking each is cheaper', function () {
    $provider = comicVineChanges();
    Http::fake(['comicvine.gamespot.com/api/volumes/*' => Http::response(comicVineList(volumeRows([4207])))]);

    expect($provider->changedAmong('comic', ['4207'], CarbonImmutable::now()->subDay())->isKnown())->toBeFalse();
    Http::assertSentCount(1);
});

test('nothing to ask about asks nothing', function () {
    $provider = comicVineChanges();
    Http::fake();

    expect($provider->changedAmong('comic', [], CarbonImmutable::now()->subDay())->refs)->toBe([]);
    Http::assertNothingSent();
});

test('a list ComicVine cannot serve is a failure, not an empty answer', function (int $status, array $body) {
    $provider = comicVineChanges();
    Http::fake(['comicvine.gamespot.com/api/volumes/*' => Http::response($body, $status)]);

    $provider->changedAmong('comic', ['4207'], CarbonImmutable::now()->subDay());
})->with([
    'a bad filter' => [200, ['status_code' => 104, 'error' => 'Filter Error', 'results' => []]],
    'the hour spent' => [200, ['status_code' => 107, 'error' => 'Rate limit exceeded', 'results' => []]],
    'down' => [502, []],
])->throws(ExternalServiceException::class);

test('comics are reported, not vouched for', function () {
    $provider = comicVineChanges();

    expect($provider->changeFeedTypes())->toBe(['comic'])
        ->and($provider->completeChangeFeedTypes())->toBe([]);
});

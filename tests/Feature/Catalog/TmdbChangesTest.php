<?php

use App\Catalog\Providers\Tmdb\TmdbChanges;
use App\Support\ExternalServiceException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// The shape is TMDB's documented one for /movie/changes and /tv/changes:
// ids only, paged, by calendar day, at most fourteen days a query.

/**
 * @param  list<int>  $ids
 * @return array<string, mixed>
 */
function changesPage(array $ids, int $page, int $pages): array
{
    return [...jsonFixture('tmdb/movie_changes'), 'results' => array_map(fn (int $id): array => ['id' => $id, 'adult' => false], $ids), 'page' => $page, 'total_pages' => $pages];
}

test('our films the list names, across its pages, from the day of the last reading', function () {
    Http::fake(['api.themoviedb.org/3/movie/changes*' => fn (Request $request) => Http::response(
        $request->data()['page'] === 1 ? changesPage([603, 11], 1, 2) : changesPage([550, 27205], 2, 2),
    )]);

    $report = tmdbProvider()->changedAmong('movie', ['603', '550', '13'], CarbonImmutable::now()->subDays(7));

    expect($report->isKnown())->toBeTrue()
        ->and($report->refs)->toBe(['603', '550']);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->data()['start_date'] === '2026-10-03' && $request->data()['end_date'] === '2026-10-10');
});

test('a show asks the list of changed shows', function () {
    Http::fake(['api.themoviedb.org/3/tv/changes*' => Http::response(changesPage([1399], 1, 1))]);

    expect(tmdbProvider()->changedAmong('show', ['1399'], CarbonImmutable::now()->subDay())->refs)->toBe(['1399']);
});

test('a window past what TMDB answers in one query is unknown, without asking', function () {
    Http::fake();
    expect(tmdbProvider()->changedAmong('movie', ['603'], CarbonImmutable::now()->subDays(TmdbChanges::WINDOW_DAYS + 1))->isKnown())->toBeFalse();
    Http::assertNothingSent();
});

test('a window TMDB answers in one query is asked', function () {
    Http::fake(['api.themoviedb.org/3/movie/changes*' => Http::response(changesPage([], 1, 1))]);

    expect(tmdbProvider()->changedAmong('movie', ['603'], CarbonImmutable::now()->subDays(TmdbChanges::WINDOW_DAYS))->refs)->toBe([]);
});

test('a list longer in pages than our titles are many is unknown: asking each is cheaper', function () {
    Http::fake(['api.themoviedb.org/3/movie/changes*' => Http::response(changesPage([603], 1, 3))]);

    expect(tmdbProvider()->changedAmong('movie', ['603', '11'], CarbonImmutable::now()->subDay())->isKnown())->toBeFalse();
    Http::assertSentCount(1);
});

test('nothing to ask about asks nothing', function () {
    Http::fake();

    expect(tmdbProvider()->changedAmong('movie', [], CarbonImmutable::now()->subDay())->refs)->toBe([]);
    Http::assertNothingSent();
});

test('a list TMDB cannot serve is a failure, not an empty answer', function (int $status) {
    Http::fake(['api.themoviedb.org/3/movie/changes*' => Http::response(['status_code' => 34], $status)]);

    tmdbProvider()->changedAmong('movie', ['603'], CarbonImmutable::now()->subDay());
})->with([404, 500])->throws(ExternalServiceException::class);

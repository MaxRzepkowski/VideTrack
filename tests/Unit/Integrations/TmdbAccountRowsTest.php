<?php

use App\Catalog\MediaKind;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\Tmdb\TmdbAccountRows;
use App\Integrations\Tmdb\TmdbListing;
use Tests\TestCase;

uses(TestCase::class);

test('a movie and a show are placed by their TMDB id, under their title and year', function (MediaKind $kind, array $item, string $label) {
    $row = (new TmdbAccountRows)->title($kind, TmdbListing::Watchlist, $item);

    expect($row)->toBeInstanceOf(MediaRow::class)
        ->and($row instanceof MediaRow ? [$row->subject->type, $row->subject->ref, $row->subject->label, $row->watchlist !== null] : null)
        ->toBe([$kind->value, (string) $item['id'], $label, true]);
})->with([
    'a movie' => [MediaKind::Movie, ['id' => 76726, 'title' => 'Chronicle', 'release_date' => '2012-02-01'], 'Chronicle (2012)'],
    'a show' => [MediaKind::Show, ['id' => 1418, 'name' => 'The Big Bang Theory', 'first_air_date' => '2007-09-24'], 'The Big Bang Theory (2007)'],
    'no date' => [MediaKind::Movie, ['id' => 5, 'title' => 'Undated', 'release_date' => ''], 'Undated'],
]);

test('a rating in halves lands on the nearest whole point, a missing one is reported', function (mixed $rating, ?int $points) {
    $row = (new TmdbAccountRows)->title(MediaKind::Movie, TmdbListing::Rated, ['id' => 120, 'title' => 'The Fellowship of the Ring', 'rating' => $rating]);

    $points === null
        ? expect($row)->toBeInstanceOf(SkipRow::class)
        : expect($row instanceof MediaRow ? $row->rating?->value : null)->toBe($points);
})->with([
    'eight and a half' => [8.5, 9],
    'seven' => [7.0, 7],
    'half a point' => [0.5, 1],
    'none' => [null, null],
]);

test('a favorite is a heart', function () {
    $row = (new TmdbAccountRows)->title(MediaKind::Show, TmdbListing::Favorites, ['id' => 1418, 'name' => 'The Big Bang Theory']);

    expect($row instanceof MediaRow && $row->favorite !== null && $row->watchlist === null)->toBeTrue();
});

test('a rated episode is placed below its show by its numbers; one without them is passed over', function () {
    $rows = new TmdbAccountRows;
    $row = $rows->episode(['show_id' => 1418, 'season_number' => 7, 'episode_number' => 5, 'name' => 'The Workplace Proximity', 'rating' => 8.0]);

    expect($row instanceof MediaRow ? [$row->subject->type, $row->subject->ref, $row->subject->path, $row->subject->label, $row->rating?->value] : null)
        ->toBe(['show', '1418', [7, 5], 'S07E05 The Workplace Proximity', 8])
        ->and($rows->episode(['show_id' => 1418, 'season_number' => 7, 'rating' => 8.0]))->toBeNull();
});

test('a list item names its kind; anything else on a list is passed over', function () {
    $rows = new TmdbAccountRows;
    $list = new ImportListRef('Test List');

    $show = $rows->listed($list, ['id' => 1418, 'media_type' => 'tv', 'name' => 'The Big Bang Theory']);

    expect($show?->subject->type)->toBe('show')
        ->and($show?->lists[0]->list)->toBe($list)
        ->and($rows->listed($list, ['id' => 287, 'media_type' => 'person', 'name' => 'Brad Pitt']))->toBeNull();
});

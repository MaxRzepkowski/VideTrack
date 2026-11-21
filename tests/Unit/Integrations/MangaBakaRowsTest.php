<?php

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\MangaBaka\MangaBakaRows;
use App\Library\RatingScale;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, mixed>  $entry
 * @param  array<string, mixed>  $series
 * @return array<string, mixed>
 */
function mangaBakaItem(array $entry, array $series = []): array
{
    return [
        'entry' => ['id' => 1, 'series_id' => 9, 'user_id' => 'u1', 'state' => 'reading', ...$entry],
        'lists' => [],
        'series' => ['id' => 9, 'titles' => [['language' => 'en', 'traits' => ['official'], 'title' => 'Monster', 'is_primary' => true]], 'source' => ['my_anime_list' => ['id' => 1, 'rating' => 9.1]], ...$series],
    ];
}

function mangaBakaRow(array $item): MediaRow
{
    $row = (new MangaBakaRows)->entry($item, []);

    return $row instanceof MediaRow ? $row : throw new LogicException('Expected a media row.');
}

test('a series is the manga of its MyAnimeList id, under its primary title', function () {
    $row = mangaBakaRow(mangaBakaItem([]));

    expect([$row->subject->type, $row->subject->provider, $row->subject->ref, $row->subject->label])->toBe(['manga', 'mal', '1', 'Monster']);
});

test('a series without a MyAnimeList id is searched by its title and year, which must match exactly', function () {
    $row = mangaBakaRow(mangaBakaItem([], ['source' => ['my_anime_list' => ['id' => null]], 'published' => ['start_date' => '1994-12-05']]));

    expect($row->subject->kind)->toBe(MediaKind::Manga)
        ->and($row->subject->byTitle?->title)->toBe('Monster')
        ->and($row->subject->byTitle?->year)->toBe(1994)
        ->and($row->subject->byTitle?->exactTitle)->toBeTrue();
});

test('the title is the primary one, else the first official, else the first', function (array $titles, string $label) {
    expect(mangaBakaRow(mangaBakaItem([], ['titles' => $titles]))->subject->label)->toBe($label);
})->with([
    'primary' => [[['title' => 'Wanpiisu', 'traits' => ['native']], ['title' => 'One Piece', 'traits' => ['official'], 'is_primary' => true]], 'One Piece'],
    'first official' => [[['title' => 'Wanpiisu', 'traits' => ['native']], ['title' => 'One Piece', 'traits' => ['official']]], 'One Piece'],
    'first' => [[['title' => 'Wanpiisu', 'traits' => ['native']]], 'Wanpiisu'],
]);

test('each state lands where it belongs, with the chapter reached', function (string $state, ?MediaUserStatus $status, bool $played, bool $dropped, bool $wanted) {
    $row = mangaBakaRow(mangaBakaItem(['state' => $state, 'progress_chapter' => 40, 'start_date' => '2025-01-01', 'finish_date' => '2025-06-01']));

    expect($row->status?->status)->toBe($status)
        ->and($row->plays !== [])->toBe($played)
        ->and($row->drop !== null)->toBe($dropped)
        ->and($row->watchlist !== null)->toBe($wanted);

    if ($status !== null) {
        expect($row->status?->progress)->toBe(40);
    }
})->with([
    ['reading', MediaUserStatus::InProgress, false, false, false],
    ['rereading', MediaUserStatus::InProgress, true, false, false],
    ['paused', MediaUserStatus::OnHold, false, false, false],
    ['completed', null, true, false, false],
    ['dropped', null, false, true, false],
    ['plan_to_read', null, false, false, true],
    ['considering', null, false, false, true],
]);

test('a rating out of 100 lands on the ten-point scale; none stays none', function (mixed $rating, ?int $points) {
    expect(mangaBakaRow(mangaBakaItem(['rating' => $rating]))->rating?->value)->toBe($points);
})->with([[85, 9], [30, 3], [1, RatingScale::MIN], [null, null]]);

test('an entry of a state this import does not know is passed over', function () {
    expect((new MangaBakaRows)->entry(mangaBakaItem(['state' => 'archived']), []))->toBeNull();
});

test('a half chapter counts as the chapter begun', function () {
    expect(mangaBakaRow(mangaBakaItem(['progress_chapter' => 12.5]))->status?->progress)->toBe(12);
});

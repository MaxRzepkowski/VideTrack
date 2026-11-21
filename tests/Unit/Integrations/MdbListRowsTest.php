<?php

use App\Catalog\MediaKind;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\MdbList\MdbListGroup;
use App\Integrations\MdbList\MdbListRows;
use App\Library\RatingScale;
use Tests\TestCase;

uses(TestCase::class);

function mdbListMediaRow(MediaRow|SkipRow|null $row): MediaRow
{
    return $row instanceof MediaRow ? $row : throw new LogicException('Expected a media row.');
}

test('a movie goes by its TMDB id, else by IMDb', function (array $ids, array $subject) {
    $row = mdbListMediaRow((new MdbListRows)->play(MdbListGroup::Movies, ['watched_at' => '2025-10-21T12:00:00Z', 'movie' => ['title' => 'Batman Begins', 'year' => 2005, 'ids' => $ids]]));

    expect([$row->subject->ref, $row->subject->kind, $row->subject->externalIds, $row->subject->label])->toBe($subject)
        ->and($row->plays[0]->at?->toIso8601ZuluString())->toBe('2025-10-21T12:00:00Z');
})->with([
    'tmdb' => [['tmdb' => 272, 'imdb' => 'tt0372784'], ['272', null, [], 'Batman Begins (2005)']],
    'imdb only' => [['imdb' => 'tt0372784', 'mdblist' => 'a388'], [null, MediaKind::Movie, ['imdb' => 'tt0372784'], 'Batman Begins (2005)']],
]);

test('an episode goes below its show by TMDB numbers, else by the show\'s other ids and its own TVDB id', function () {
    $rows = new MdbListRows;
    $episode = fn (array $showIds) => ['watched_at' => '2025-10-15T20:00:00Z', 'episode' => ['season' => 1, 'number' => 2, 'ids' => ['tmdb' => 62086, 'tvdb' => 349233], 'show' => ['title' => 'Breaking Bad', 'ids' => $showIds]]];

    $placed = mdbListMediaRow($rows->play(MdbListGroup::Episodes, $episode(['tmdb' => 1396])));
    $foreign = mdbListMediaRow($rows->play(MdbListGroup::Episodes, $episode(['tvdb' => 81189])));

    expect([$placed->subject->type, $placed->subject->ref, $placed->subject->path, $placed->subject->label])->toBe(['show', '1396', [1, 2], 'Breaking Bad S01E02'])
        ->and([$foreign->subject->kind, $foreign->subject->externalIds, $foreign->subject->seriesExternalIds, $foreign->subject->season, $foreign->subject->episode])
        ->toBe([MediaKind::Episode, ['tvdb' => '349233'], ['tvdb' => '81189'], 1, 2]);
});

test('an entry without ids, numbers or a date is reported, not guessed', function (MdbListGroup $group, array $entry, string $key) {
    $row = (new MdbListRows)->play($group, $entry);

    expect($row)->toBeInstanceOf(SkipRow::class)
        ->and($row instanceof SkipRow ? $row->key : null)->toBe($key);
})->with([
    'no ids' => [MdbListGroup::Movies, ['watched_at' => '2025-10-21T12:00:00Z', 'movie' => ['title' => 'Nowhere', 'ids' => ['mdblist' => 'zz9']]], 'import.skip_no_id_to_look_up'],
    'no number' => [MdbListGroup::Episodes, ['watched_at' => '2025-10-21T12:00:00Z', 'episode' => ['season' => 1, 'show' => ['ids' => ['tmdb' => 1396]]]], 'import.skip_no_id_to_look_up'],
    'no date' => [MdbListGroup::Movies, ['movie' => ['title' => 'Batman Begins', 'ids' => ['tmdb' => 272]]], 'import.skip_no_watched_at'],
]);

test('a rating comes over as MDBList rounds it, a missing one is reported', function (mixed $rating, ?int $kept) {
    $row = (new MdbListRows)->rating(MdbListGroup::Seasons, ['rated_at' => '2025-10-20T10:05:00Z', 'rating' => $rating, 'season' => ['number' => 1, 'show' => ['title' => 'Breaking Bad', 'ids' => ['tmdb' => 1396]]]]);

    $kept === null
        ? expect($row)->toBeInstanceOf(SkipRow::class)
        : expect(mdbListMediaRow($row)->rating?->value)->toBe($kept)
            ->and(mdbListMediaRow($row)->subject->path)->toBe([1]);
})->with([
    'whole' => [7, 7],
    'the top' => [RatingScale::MAX, RatingScale::MAX],
    'none' => [null, null],
    'off the scale, for the runner to turn down' => [11, 11],
]);

test('a list item is placed by its ids, a show by its flat TMDB id, an unknown kind passed over', function () {
    $rows = new MdbListRows;
    $list = new ImportListRef('Halloween Night');

    $show = mdbListMediaRow($rows->listed(['id' => 258902, 'title' => 'English Teacher', 'imdb_id' => 'tt20782190', 'tvdb_id' => 421968, 'mediatype' => 'show', 'release_year' => 2024], null));
    $movie = mdbListMediaRow($rows->listed(['id' => 917496, 'title' => 'Beetlejuice Beetlejuice', 'ids' => ['tmdb' => 917496], 'mediatype' => 'movie'], $list));

    expect([$show->subject->type, $show->subject->ref, $show->subject->label])->toBe(['show', '258902', 'English Teacher (2024)'])
        ->and($show->watchlist)->not->toBeNull()
        ->and($movie->lists[0]->list)->toBe($list)
        ->and($movie->watchlist)->toBeNull()
        ->and($rows->listed(['id' => 1, 'title' => 'A person', 'mediatype' => 'person'], null))->toBeNull();
});

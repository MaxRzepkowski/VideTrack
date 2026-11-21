<?php

use App\Enums\MediaUserStatus;
use App\Integrations\Audiobookshelf\AudiobookshelfRows;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, mixed>  $progress
 * @return array<string, mixed>
 */
function absProgress(array $progress = []): array
{
    return ['libraryItemId' => 'li-1', 'episodeId' => null, 'mediaItemType' => 'book', 'progress' => 0, 'ebookProgress' => null, 'isFinished' => false, 'startedAt' => 1758000000000, 'finishedAt' => null, ...$progress];
}

/**
 * @param  array<string, mixed>  $metadata
 * @return array<string, mixed>
 */
function absItem(array $metadata = []): array
{
    return ['id' => 'li-1', 'mediaType' => 'book', 'media' => ['metadata' => ['title' => 'Dune', 'authors' => [['id' => 'a1', 'name' => 'Frank Herbert'], ['id' => 'a2', 'name' => 'Someone Else']], 'isbn' => null, ...$metadata]]];
}

function absRow(array $progress, array $item): MediaRow
{
    $row = (new AudiobookshelfRows)->row($progress, $item);

    return $row instanceof MediaRow ? $row : throw new LogicException('Expected a media row.');
}

test('only a book finished or begun is worth asking about', function (array $progress, bool $wanted) {
    expect((new AudiobookshelfRows)->wanted(absProgress($progress)))->toBe($wanted);
})->with([
    'finished' => [['isFinished' => true, 'progress' => 1], true],
    'listened to in part' => [['progress' => 0.3], true],
    'read in part as an e-book' => [['ebookProgress' => 0.12], true],
    'only opened' => [[], false],
    'a podcast episode' => [['mediaItemType' => 'podcastEpisode', 'episodeId' => 'ep-1', 'isFinished' => true], false],
]);

test('a book with an ISBN is placed by it, in its thirteen-digit form', function () {
    $row = absRow(absProgress(['isFinished' => true]), absItem(['isbn' => '0-441-01359-7']));

    expect($row->subject->externalIds)->toBe(['isbn' => '9780441013593'])
        ->and($row->subject->byTitle?->title)->toBe('Dune');
});

test('a book without one is searched by its title and first author', function () {
    $row = absRow(absProgress(['progress' => 0.4]), absItem());

    expect($row->subject->externalIds)->toBe([])
        ->and([$row->subject->byTitle?->title, $row->subject->byTitle?->creator, $row->subject->byTitle?->year])->toBe(['Dune', 'Frank Herbert', null]);
});

test('a finished book is read on its day, one under way is in progress since it began', function () {
    $finished = absRow(absProgress(['isFinished' => true, 'finishedAt' => 1758403200000]), absItem());
    $begun = absRow(absProgress(['progress' => 0.4]), absItem());

    expect($finished->plays[0]->at?->toIso8601ZuluString())->toBe('2025-09-20T21:20:00Z')
        ->and($finished->status)->toBeNull()
        ->and($begun->plays)->toBe([])
        ->and($begun->status?->status)->toBe(MediaUserStatus::InProgress)
        ->and($begun->status?->at?->toIso8601ZuluString())->toBe('2025-09-16T05:20:00Z')
        ->and($begun->status?->progress)->toBeNull();
});

test('a finished book without a day is read without one, not on a made-up day', function () {
    expect(absRow(absProgress(['isFinished' => true]), absItem())->plays[0]->at)->toBeNull();
});

test('a book without a title says so', function () {
    expect((new AudiobookshelfRows)->row(absProgress(['isFinished' => true]), absItem(['title' => ' '])))->toBeInstanceOf(SkipRow::class);
});

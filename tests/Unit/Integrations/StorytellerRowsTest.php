<?php

use App\Enums\MediaUserStatus;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\Storyteller\StorytellerRows;
use App\Library\PlayDating;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, mixed>  $book
 * @return array<string, mixed>
 */
function storytellerBook(array $book = []): array
{
    return ['title' => 'Dune', 'authors' => [['name' => 'Frank Herbert'], ['name' => 'Brian Herbert']], 'identifiers' => [], 'status' => ['name' => 'Read'], 'position' => null, 'readaloud' => null, 'ebook' => null, 'audiobook' => null, ...$book];
}

function storytellerRow(array $book): MediaRow
{
    $row = (new StorytellerRows)->row($book);

    return $row instanceof MediaRow ? $row : throw new LogicException('Expected a media row.');
}

test('only Reading and Read say anything; the default and a status of the user\'s own do not', function (?string $status, bool $says) {
    $row = (new StorytellerRows)->row(storytellerBook(['status' => $status === null ? null : ['name' => $status]]));

    expect($row !== null)->toBe($says);
})->with([
    'read' => ['Read', true],
    'reading' => ['Reading', true],
    'to read, every book\'s default' => ['To read', false],
    'one of the user\'s own' => ['Favourites', false],
    'none' => [null, false],
]);

test('a book is placed by its Hardcover id, else its ISBN, else its title and first author', function () {
    $byHardcover = storytellerRow(storytellerBook(['identifiers' => [['kind' => 'isbn-13', 'value' => '9780441013593'], ['kind' => 'hardcover-book-id', 'value' => '312460']]]));
    $byIsbn = storytellerRow(storytellerBook(['ebook' => ['identifiers' => [['kind' => 'isbn-13', 'value' => '978-0-441-01359-3']]]]));
    $byTitle = storytellerRow(storytellerBook());

    $fromReadaloud = storytellerRow(storytellerBook(['readaloud' => ['identifiers' => [['kind' => 'hardcover-book-id', 'value' => '312460']]]]));
    $fromAudiobook = storytellerRow(storytellerBook(['audiobook' => ['identifiers' => [['kind' => 'hardcover-book-id', 'value' => '312460']]]]));
    $badIsbn = storytellerRow(storytellerBook(['identifiers' => [['kind' => 'isbn-13', 'value' => 'not an isbn']]]));
    $unknownKind = storytellerRow(storytellerBook(['identifiers' => [['kind' => null, 'value' => '5907']]]));

    expect([$byHardcover->subject->provider, $byHardcover->subject->ref])->toBe(['hardcover', '312460'])
        ->and([$fromReadaloud->subject->ref, $fromAudiobook->subject->ref])->toBe(['312460', '312460'])
        ->and([$badIsbn->subject->externalIds, $badIsbn->subject->byTitle?->title])->toBe([[], 'Dune'])
        ->and($unknownKind->subject->externalIds)->toBe([])
        ->and($byIsbn->subject->externalIds)->toBe(['isbn' => '9780441013593'])
        ->and([$byTitle->subject->externalIds, $byTitle->subject->byTitle?->title, $byTitle->subject->byTitle?->creator])->toBe([[], 'Dune', 'Frank Herbert']);
});

test('a read is dated by its position only when the position stands at the end', function (mixed $locator, ?string $at) {
    $row = storytellerRow(storytellerBook(['position' => ['locator' => $locator, 'timestamp' => 1758403200000, 'createdAt' => '2025-09-01 18:00:00']]));

    expect($row->plays[0]->at?->toIso8601ZuluString())->toBe($at);
})->with([
    'at the end' => [['locations' => ['totalProgression' => 0.99]], '2025-09-20T21:20:00Z'],
    'marked read midway' => [['locations' => ['totalProgression' => 0.4]], null],
]);

test('the book\'s own identifier wins over its files\'', function () {
    $row = storytellerRow(storytellerBook([
        'identifiers' => [['kind' => 'isbn-13', 'value' => '9780441013593']],
        'ebook' => ['identifiers' => [['kind' => 'isbn-13', 'value' => '9780547928227']]],
    ]));

    expect($row->subject->externalIds)->toBe(['isbn' => '9780441013593']);
});

test('a read is the last activity, so a moment that moves on makes no second read', function () {
    expect(storytellerRow(storytellerBook())->plays[0]->dating)->toBe(PlayDating::Activity);
});

test('a book marked read with no position has no day, none is made up', function () {
    expect(storytellerRow(storytellerBook())->plays[0]->at)->toBeNull();
});

test('a book under way is in progress since its first position, with no counter', function () {
    $row = storytellerRow(storytellerBook(['status' => ['name' => 'Reading'], 'position' => ['locator' => [], 'timestamp' => 1758403200000, 'createdAt' => '2025-09-01 18:00:00']]));

    expect($row->plays)->toBe([])
        ->and($row->status?->status)->toBe(MediaUserStatus::InProgress)
        // Storyteller writes UTC without a zone.
        ->and($row->status?->at?->toIso8601ZuluString())->toBe('2025-09-01T18:00:00Z')
        ->and($row->status?->progress)->toBeNull();
});

test('a book under way with no position is in progress without a day', function () {
    $row = storytellerRow(storytellerBook(['status' => ['name' => 'Reading']]));

    expect([$row->status?->status, $row->status?->at])->toBe([MediaUserStatus::InProgress, null]);
});

test('a book without a title says so', function () {
    expect((new StorytellerRows)->row(storytellerBook(['title' => ''])))->toBeInstanceOf(SkipRow::class);
});

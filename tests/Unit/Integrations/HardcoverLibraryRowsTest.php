<?php

use App\Enums\MediaUserStatus;
use App\Integrations\Hardcover\HardcoverLibraryRows;
use App\Integrations\Hardcover\HardcoverReadingStatus;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use Tests\TestCase;

uses(TestCase::class);

/**
 * One `user_books` entry, with what a test does not name left empty.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function hardcoverBook(HardcoverReadingStatus|int $status, array $fields = []): array
{
    return [
        'book_id' => 7,
        'status_id' => $status instanceof HardcoverReadingStatus ? $status->value : $status,
        'rating' => null,
        'date_added' => '2024-01-05',
        'first_started_reading_date' => null,
        'last_read_date' => null,
        'book' => ['title' => 'Piranesi'],
        'user_book_reads' => [],
        ...$fields,
    ];
}

function hardcoverRow(array $book): MediaRow
{
    $row = (new HardcoverLibraryRows)->book($book);

    return $row instanceof MediaRow ? $row : throw new LogicException('Expected a media row.');
}

/**
 * @return list<string|null>
 */
function hardcoverPlayDays(MediaRow $row): array
{
    return array_map(fn ($play) => $play->at?->toDateString(), $row->plays);
}

test('a book is the Hardcover book of its id, under its title', function () {
    $row = hardcoverRow(hardcoverBook(HardcoverReadingStatus::WantToRead));

    expect([$row->subject->type, $row->subject->provider, $row->subject->ref, $row->subject->label])->toBe(['book', 'hardcover', '7', 'Piranesi']);
});

test('every finished read-through is a read, whatever the status now', function (HardcoverReadingStatus $status) {
    $row = hardcoverRow(hardcoverBook($status, ['user_book_reads' => [
        ['started_at' => '2023-01-02', 'finished_at' => '2023-01-20', 'progress_pages' => 300],
        ['started_at' => '2025-03-01', 'finished_at' => null, 'progress_pages' => 40],
    ]]));

    expect(hardcoverPlayDays($row))->toBe(['2023-01-20']);
})->with([HardcoverReadingStatus::Read, HardcoverReadingStatus::CurrentlyReading, HardcoverReadingStatus::WantToRead]);

test('a book marked read without a finished read-through is one read, on the last read date or undated', function (?string $lastRead, array $days) {
    expect(hardcoverPlayDays(hardcoverRow(hardcoverBook(HardcoverReadingStatus::Read, ['last_read_date' => $lastRead]))))->toBe($days);
})->with([
    'a last read date' => ['2022-08-14', ['2022-08-14']],
    'nothing to date it' => [null, [null]],
]);

test('reading and paused are open attempts from the latest start, with its pages', function (HardcoverReadingStatus $status, MediaUserStatus $open) {
    $row = hardcoverRow(hardcoverBook($status, [
        'first_started_reading_date' => '2020-05-01',
        'user_book_reads' => [['started_at' => '2026-09-01', 'finished_at' => null, 'progress_pages' => 212]],
    ]));

    expect($row->status?->status)->toBe($open)
        ->and($row->status?->at?->toDateString())->toBe('2026-09-01')
        ->and($row->status?->progress)->toBe(212)
        ->and($row->plays)->toBe([]);
})->with([
    [HardcoverReadingStatus::CurrentlyReading, MediaUserStatus::InProgress],
    [HardcoverReadingStatus::Paused, MediaUserStatus::OnHold],
]);

test('did not finish is a drop on the last read date, else the latest start, else undated', function (array $fields, ?string $day) {
    $row = hardcoverRow(hardcoverBook(HardcoverReadingStatus::DidNotFinish, $fields));

    expect($row->drop?->at?->toDateString())->toBe($day)
        ->and($row->drop)->not->toBeNull()
        ->and($row->status)->toBeNull();
})->with([
    'a last read date' => [['last_read_date' => '2021-04-10', 'user_book_reads' => [['started_at' => '2021-03-01', 'finished_at' => null, 'progress_pages' => 150]]], '2021-04-10'],
    'only a start' => [['user_book_reads' => [['started_at' => '2021-03-01', 'finished_at' => null, 'progress_pages' => null]]], '2021-03-01'],
    'no dates at all' => [[], null],
]);

test('want to read is the watchlist, from the day it was added', function () {
    expect(hardcoverRow(hardcoverBook(HardcoverReadingStatus::WantToRead))->watchlist?->at?->toDateString())->toBe('2024-01-05');
});

test('a rating in stars lands on the ten-point scale, dated by the last finished read', function (mixed $rating, ?int $points) {
    $row = hardcoverRow(hardcoverBook(HardcoverReadingStatus::Read, [
        'rating' => $rating,
        'last_read_date' => '2024-06-01',
        'user_book_reads' => [['started_at' => null, 'finished_at' => '2023-02-01', 'progress_pages' => null], ['started_at' => null, 'finished_at' => '2024-05-12', 'progress_pages' => null]],
    ]));

    expect($row->rating?->value)->toBe($points);

    if ($points !== null) {
        expect($row->rating?->at?->toDateString())->toBe('2024-05-12');
    }
})->with([
    'half a star' => [0.5, 1],
    'four and a half' => [4.5, 9],
    'five' => [5, 10],
    'sent as text' => ['3.5', 7],
    'unrated' => [0, null],
    'none' => [null, null],
    'off the scale' => [7, null],
]);

test('an ignored book stays out, an unknown status is reported, a book without an id is passed over', function () {
    $rows = new HardcoverLibraryRows;
    $unknown = $rows->book(hardcoverBook(9));

    expect($rows->book(hardcoverBook(HardcoverReadingStatus::Ignored)))->toBeNull()
        ->and($unknown)->toBeInstanceOf(SkipRow::class)
        ->and($unknown instanceof SkipRow ? $unknown->replace : null)->toBe(['label' => 'Piranesi'])
        ->and($rows->book(hardcoverBook(HardcoverReadingStatus::Read, ['book_id' => null])))->toBeNull();
});

test('a book without a title goes by its id', function () {
    expect(hardcoverRow(hardcoverBook(HardcoverReadingStatus::Read, ['book' => null]))->subject->label)->toBe('Book 7');
});

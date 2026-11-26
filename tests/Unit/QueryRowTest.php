<?php

use App\Models\MediaItem;
use App\Support\QueryRow;

function queryRow(mixed $value): stdClass
{
    return (object) ['column' => $value];
}

test('a required whole number reads a number or digits', function (mixed $value, int $expected) {
    expect(QueryRow::int(queryRow($value), 'column'))->toBe($expected);
})->with([
    'int' => [42, 42],
    'zero' => [0, 0],
    'whole float' => [3.0, 3],
    'digits' => ['17', 17],
]);

test('a required whole number refuses what the query never promised', function (mixed $value) {
    QueryRow::int(queryRow($value), 'column');
})->with([
    'null' => [null],
    'fraction' => [2.5],
    'decimal text' => ['2.5'],
    'text' => ['two'],
    'bool' => [true],
])->throws(UnexpectedValueException::class, 'column [column]');

test('a nullable whole number is null only for NULL', function () {
    expect(QueryRow::intOrNull(queryRow(null), 'column'))->toBeNull()
        ->and(QueryRow::intOrNull(queryRow('8'), 'column'))->toBe(8)
        ->and(QueryRow::intOrNull(queryRow(0), 'column'))->toBe(0);
});

test('a nullable whole number still refuses another type', function () {
    QueryRow::intOrNull(queryRow(1.5), 'column');
})->throws(UnexpectedValueException::class);

test('required text is returned as stored, an empty string included', function () {
    expect(QueryRow::text(queryRow(' show '), 'column'))->toBe(' show ')
        ->and(QueryRow::text(queryRow(''), 'column'))->toBe('');
});

test('required text refuses NULL and numbers', function (mixed $value) {
    QueryRow::text(queryRow($value), 'column');
})->with([
    'null' => [null],
    'int' => [7],
])->throws(UnexpectedValueException::class);

test('nullable text is null only for NULL', function () {
    expect(QueryRow::textOrNull(queryRow(null), 'column'))->toBeNull()
        ->and(QueryRow::textOrNull(queryRow('2026-10-10'), 'column'))->toBe('2026-10-10');
});

test('nullable text still refuses another type', function () {
    QueryRow::textOrNull(queryRow(3), 'column');
})->throws(UnexpectedValueException::class);

test('a column the row does not carry is a bug, also for the nullable readers', function (string $reader) {
    QueryRow::{$reader}((object) ['other' => 1], 'column');
})->with(['int', 'intOrNull', 'text', 'textOrNull'])->throws(UnexpectedValueException::class, 'has no column [column]');

test('an alias a query added to a model is read from its raw attributes', function () {
    $item = (new MediaItem)->setRawAttributes(['list_id' => '12', 'title' => 'Andor']);

    expect(QueryRow::int($item, 'list_id'))->toBe(12)
        ->and(QueryRow::text($item, 'title'))->toBe('Andor');
});

test('a model without the alias is a bug', function () {
    QueryRow::int((new MediaItem)->setRawAttributes(['id' => 1]), 'list_id');
})->throws(UnexpectedValueException::class, 'has no column [list_id]');

test('the error names the column, never its value', function () {
    try {
        QueryRow::int(queryRow('secret-token'), 'token');
    } catch (UnexpectedValueException $e) {
        expect($e->getMessage())->toContain('[token]')->not->toContain('secret-token');

        return;
    }

    $this->fail('expected an exception');
});

test('a moment is read on the clock the database keeps time on', function () {
    onClock('Europe/Warsaw', function () {
        $moment = QueryRow::moment(queryRow('2026-10-10 14:00:00'), 'column');

        expect($moment->timezoneName)->toBe('Europe/Warsaw')
            ->and($moment->toIso8601ZuluString())->toBe('2026-10-10T12:00:00Z')
            ->and(QueryRow::momentOrNull(queryRow(null), 'column'))->toBeNull();
    });
});

test('a moment refuses what is none, where Carbon would read blank as now', function (mixed $value) {
    QueryRow::moment(queryRow($value), 'column');
})->with([
    'null' => [null],
    'blank' => ['  '],
    'not a moment' => ['soon'],
    'number' => [42],
])->throws(UnexpectedValueException::class, 'column [column]');

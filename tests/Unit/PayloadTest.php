<?php

use App\Catalog\Data\KeywordData;
use App\Catalog\Providers\Igdb\GameType;
use App\Catalog\VideoKind;
use App\Support\Payload;

test('a string is present only when it has content', function (mixed $value, ?string $expected) {
    expect(Payload::string(['key' => $value], 'key'))->toBe($expected);
})->with([
    'text' => ['Dune', 'Dune'],
    'padded' => ["  Dune \n", 'Dune'],
    'blank' => ['   ', null],
    'empty' => ['', null],
    'number' => [42, null],
    'null' => [null, null],
]);

test('an enum value is one the enum knows', function (mixed $value, ?VideoKind $expected) {
    expect(Payload::enum(['key' => $value], 'key', VideoKind::class))->toBe($expected);
})->with([
    'known' => ['Trailer', VideoKind::Trailer],
    'with spaces' => ['Behind the Scenes', VideoKind::BehindTheScenes],
    'unknown' => ['Opening Credits', null],
    'blank' => ['', null],
    'number' => [1, null],
]);

test('a numeric code is one the int enum knows', function (mixed $value, ?GameType $expected) {
    expect(Payload::intEnum(['key' => $value], 'key', GameType::class))->toBe($expected);
})->with([
    'known' => [1, GameType::Dlc],
    'unknown' => [99, null],
    'as text' => ['1', null],
    'null' => [null, null],
]);

test('a missing key is absent', function () {
    expect(Payload::string([], 'key'))->toBeNull();
});

test('names are plucked from the entries that have one', function () {
    expect(Payload::names([['name' => 'Drama'], ['id' => 3], ['name' => ''], 'junk', ['name' => ' Crime ']]))
        ->toBe(['Drama', 'Crime'])
        ->and(Payload::names(null))->toBe([]);
});

test('an instant is read in any format and blank is not now', function () {
    expect(Payload::instant('2025-01-10T20:00:00.000Z')?->toIso8601String())->toBe('2025-01-10T20:00:00+00:00')
        ->and(Payload::instant('2021/10/11')?->toDateString())->toBe('2021-10-11')
        ->and(Payload::instant(''))->toBeNull()
        ->and(Payload::instant('   '))->toBeNull()
        ->and(Payload::instant('not a date at all'))->toBeNull()
        ->and(Payload::instant(1700000000))->toBeNull();
});

test('a moment written without a zone is read in the zone its service keeps, whatever the instance\'s', function () {
    onClock('Europe/Warsaw', fn () => expect(Payload::instant('2025-09-01 18:00:00', 'UTC')?->toIso8601ZuluString())->toBe('2025-09-01T18:00:00Z')
        ->and(Payload::instant('2025-09-01T18:00:00+02:00', 'UTC')?->toIso8601ZuluString())->toBe('2025-09-01T16:00:00Z'));
});

test('a moment in epoch milliseconds is read as such, and zero is never', function () {
    expect(Payload::epochMilliseconds(['at' => 1758403200000], 'at')?->toIso8601ZuluString())->toBe('2025-09-20T21:20:00Z')
        ->and(Payload::epochMilliseconds(['at' => '1758403200000'], 'at')?->toIso8601ZuluString())->toBe('2025-09-20T21:20:00Z')
        ->and(Payload::epochMilliseconds(['at' => 0], 'at'))->toBeNull()
        ->and(Payload::epochMilliseconds(['at' => null], 'at'))->toBeNull()
        ->and(Payload::epochMilliseconds(['at' => '2025-09-20'], 'at'))->toBeNull()
        ->and(Payload::epochMilliseconds([], 'at'))->toBeNull();
});

test('a moment in epoch seconds is read as such, and zero is never', function () {
    expect(Payload::epochSeconds(1758403200)?->toIso8601ZuluString())->toBe('2025-09-20T21:20:00Z')
        ->and(Payload::epochSeconds('1758403200')?->toIso8601ZuluString())->toBe('2025-09-20T21:20:00Z')
        ->and(Payload::epochSeconds(0))->toBeNull()
        ->and(Payload::epochSeconds(-5))->toBeNull()
        ->and(Payload::epochSeconds(null))->toBeNull()
        ->and(Payload::epochSeconds('2025-09-20'))->toBeNull();
});

test('a moment comes back on the clock the instance keeps time in, a bare day as the day it names', function () {
    // The database binds a moment by its wall clock: one left in UTC would be
    // written, and compared, an hour or two off on an instance in Warsaw.
    onClock('Europe/Warsaw', fn () => expect(Payload::instant('2025-01-10T20:00:00.000Z')?->toDateTimeString())->toBe('2025-01-10 21:00:00')
        ->and(Payload::instant('2025-09-01 18:00:00', 'UTC')?->toDateTimeString())->toBe('2025-09-01 20:00:00')
        ->and(Payload::epochMilliseconds(['at' => 1758403200000], 'at')?->toDateTimeString())->toBe('2025-09-20 23:20:00')
        ->and(Payload::epochSeconds(1758403200)?->toDateTimeString())->toBe('2025-09-20 23:20:00')
        ->and(Payload::instant('2025-01-10T20:00:00Z')?->timezoneName)->toBe('Europe/Warsaw'));

    // Twelve hours ahead of UTC, noon UTC on a bare day is past midnight.
    onClock('Pacific/Auckland', fn () => expect(Payload::instant('2024-03-01')?->toDateString())->toBe('2024-03-01'));
});

test('a positive int is a count or an id above zero, from a number or a numeric string', function () {
    expect(Payload::positiveInt(['n' => 12], 'n'))->toBe(12)
        ->and(Payload::positiveInt(['n' => '7'], 'n'))->toBe(7)
        ->and(Payload::positiveInt(['n' => 0], 'n'))->toBeNull()
        ->and(Payload::positiveInt(['n' => -3], 'n'))->toBeNull()
        ->and(Payload::positiveInt(['n' => 'many'], 'n'))->toBeNull()
        ->and(Payload::positiveInt([], 'n'))->toBeNull();
});

test('a date opens with its year', function () {
    expect(Payload::yearOf('2026-10-10'))->toBe(2026)
        ->and(Payload::yearOf('1999'))->toBe(1999)
        ->and(Payload::yearOf('soon'))->toBeNull()
        ->and(Payload::yearOf(null))->toBeNull();
});

test('a list keeps its strings and a map its string entries', function () {
    expect(Payload::strings(['Rome', 3, null, 'Paris']))->toBe(['Rome', 'Paris'])
        ->and(Payload::strings('Rome'))->toBe([])
        ->and(Payload::stringMap(['imdb' => 'https://imdb.example/tt1', 0 => 'https://lost.example', 'wiki' => ['x']]))->toBe(['imdb' => 'https://imdb.example/tt1'])
        ->and(Payload::stringMap(null))->toBe([]);
});

test('texts keep the strings of a list that say something, trimmed', function () {
    expect(Payload::texts([' AoT ', '', '  ', 3, 'Titan']))->toBe(['AoT', 'Titan'])
        ->and(Payload::texts(null))->toBe([]);
});

test('an id is a string whether the service sent a number or a string', function () {
    expect(Payload::id(603))->toBe('603')
        ->and(Payload::id('tt0133093'))->toBe('tt0133093')
        ->and(Payload::id(''))->toBeNull()
        ->and(Payload::id(6.03))->toBeNull()
        ->and(Payload::ids(['tmdb' => 603, 'imdb' => 'tt0133093', 'tvdb' => null, 0 => '7', 'slug' => '']))->toBe(['tmdb' => '603', 'imdb' => 'tt0133093'])
        ->and(Payload::ids('603'))->toBe([]);
});

test('a bare calendar day is noon UTC, so every timezone reads the same day', function () {
    expect(Payload::instant('2024-03-01')?->toIso8601String())->toBe('2024-03-01T12:00:00+00:00')
        ->and(Payload::instant('2021/10/11')?->toIso8601String())->toBe('2021-10-11T12:00:00+00:00')
        ->and(Payload::instant('2024-02-30'))->toBeNull()
        ->and(Payload::instant('2024-03-01T21:30:00Z')?->toIso8601String())->toBe('2024-03-01T21:30:00+00:00')
        ->and(Payload::day(2018, 3, 4)->setTimezone('America/Los_Angeles')->toDateString())->toBe('2018-03-04')
        ->and(Payload::day(2018, 3, 4)->setTimezone('Asia/Tokyo')->toDateString())->toBe('2018-03-04');
});

test('a required string is empty when the service left it out', function () {
    expect(Payload::text(['name' => ' Dune '], 'name'))->toBe('Dune')
        ->and(Payload::text(['name' => 12], 'name'))->toBe('')
        ->and(Payload::text([], 'name'))->toBe('');
});

test('an int is a whole number, zero and below included, also sent as text', function (mixed $value, ?int $expected) {
    expect(Payload::int(['n' => $value], 'n'))->toBe($expected);
})->with([
    'int' => [12, 12],
    'zero' => [0, 0],
    'negative' => [-3, -3],
    'whole float' => [4.0, 4],
    'fraction' => [4.5, null],
    'digits as text' => [' 7 ', 7],
    'negative as text' => ['-2', -2],
    'number with junk' => ['12abc', null],
    'fraction as text' => ['1.5', null],
    'blank' => ['', null],
    'bool' => [true, null],
    'null' => [null, null],
    'list' => [[1], null],
]);

test('a missing key has no int', function () {
    expect(Payload::int([], 'n'))->toBeNull();
});

test('a float is any number, also sent as text', function (mixed $value, ?float $expected) {
    expect(Payload::float(['n' => $value], 'n'))->toBe($expected);
})->with([
    'float' => [7.25, 7.25],
    'int' => [8, 8.0],
    'zero' => [0, 0.0],
    'as text' => ['6.5', 6.5],
    'junk' => ['high', null],
    'bool' => [false, null],
    'null' => [null, null],
]);

test('a bool is only one the service sent as such', function (mixed $value, ?bool $expected) {
    expect(Payload::bool(['b' => $value], 'b'))->toBe($expected);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    'one' => [1, null],
    'text' => ['true', null],
    'null' => [null, null],
]);

test('a list keeps its objects and a map is an object or nothing', function () {
    expect(Payload::list([['id' => 1], 'junk', 3, ['id' => 2]]))->toBe([['id' => 1], ['id' => 2]])
        ->and(Payload::list(['a' => ['id' => 1]]))->toBe([['id' => 1]])
        ->and(Payload::list('nope'))->toBe([])
        ->and(Payload::list(null))->toBe([])
        ->and(Payload::map(['imdb_id' => 'tt1']))->toBe(['imdb_id' => 'tt1'])
        ->and(Payload::map('tt1'))->toBe([])
        ->and(Payload::map(null))->toBe([])
        ->and(Payload::named(['tvmazeId' => 7, 5 => 'lost', 'genres' => ['x']]))->toBe(['tvmazeId' => 7, 'genres' => ['x']])
        ->and(Payload::named('tt1'))->toBe([]);
});

test('a list of identifiers reads numbers and strings alike, dropping the rest', function () {
    expect(Payload::idList([1, '2', '', null, ['id' => 3], 4]))->toBe(['1', '2', '4'])
        ->and(Payload::idList('7'))->toBe([]);
});

test('a list of objects reads entry by entry, dropping what does not read and keeping the order', function () {
    $read = KeywordData::fromStored(...);

    expect(Payload::listOf([['name' => 'a'], ['name' => 1], 'not an object', ['name' => 'b']], $read))->toEqual([new KeywordData('a'), new KeywordData('b')])
        ->and(Payload::listOf('not a list', $read))->toBe([]);
});

test('a calendar day is read only when the value opens with one, year first', function (mixed $value, ?string $day) {
    expect(Payload::calendarDay($value)?->toDateTimeString())->toBe($day);
})->with([
    'a day' => ['2024-04-25', '2024-04-25 12:00:00'],
    'slashes, no padding' => ['2024/4/5', '2024-04-05 12:00:00'],
    'the head of a moment' => ['2021-10-02T06:54:47Z', '2021-10-02 12:00:00'],
    'no such day' => ['2023-02-30', null],
    'a year alone' => ['2019', null],
    'a placeholder' => ['None', null],
    'day first' => ['16.11.2026', null],
    'before the common era' => ['0701-01-01 BC', null],
    'not text' => [20240425, null],
]);

test('a yes or no written as text is yes for true and yes in any case', function (string $value, bool $yes) {
    expect(Payload::flag(['flag' => $value], 'flag'))->toBe($yes);
})->with([
    '.NET' => ['True', true],
    'lower case' => ['true', true],
    'yes' => ['yes', true],
    'no' => ['no', false],
    'false' => ['False', false],
    'blank' => ['', false],
]);

<?php

use App\Support\RegionalDate;

test('a date as a region writes it names its day, in the order the value can only mean', function (string $value, ?string $day) {
    expect(RegionalDate::day($value)?->toDateString())->toBe($day);
})->with([
    'year first' => ['2026-11-16 15:04:05', '2026-11-16'],
    'American, a 12-hour clock' => ['3/9/2024 11:02:13 PM', '2024-03-09'],
    'day first with dots' => ['09.03.2024 23:02:13', '2024-03-09'],
    'day first with slashes, a 24-hour clock' => ['09/03/2024 23:02:13', '2024-03-09'],
    'a day above 12 decides' => ['24/02/2017', '2017-02-24'],
    'a day above 12 decides, the other way' => ['2/24/2017', '2017-02-24'],
    'no such day' => ['31.02.2024', null],
    'not a date' => ['yesterday', null],
]);

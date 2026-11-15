<?php

use App\Accounts\UserCalendar;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

test("a moment falls on the day of the user's own calendar", function (string $timezone, string $day) {
    expect((new UserCalendar($timezone))->day(CarbonImmutable::parse('2026-03-01 23:30', 'UTC')))->toBe($day);
})->with([
    'east of Greenwich, already the next day' => ['Europe/Warsaw', '2026-03-02'],
    'west of it, still the same' => ['America/New_York', '2026-03-01'],
]);

test('no moment is no day', function () {
    expect((new UserCalendar('UTC'))->day(null))->toBeNull();
});

test("a day begins and ends at the user's own midnight", function () {
    $calendar = new UserCalendar('Europe/Warsaw');

    expect($calendar->startOf('2026-03-02')->utc()->toDateTimeString())->toBe('2026-03-01 23:00:00')
        ->and($calendar->endOf('2026-03-02')->utc()->toDateTimeString())->toBe('2026-03-02 22:59:59');
});

test('a day edge refuses what is not a day', function () {
    (new UserCalendar('UTC'))->startOf('next week');
})->throws(InvalidFormatException::class);

test("today is the user's, already tomorrow east of a server still on today", function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 23:30', 'UTC'));

    try {
        expect((new UserCalendar('Europe/Warsaw'))->today()->toDateString())->toBe('2026-10-11')
            ->and((new UserCalendar('America/New_York'))->today()->toDateString())->toBe('2026-10-10');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

<?php

use App\Integrations\WeTrakr\WeTrakrArchive;

test('a WeTrakr moment is read as JavaScript writes it, on the stored clock', function (string $text, ?string $moment) {
    expect(WeTrakrArchive::date($text)?->toIso8601String())->toBe($moment);
})->with([
    'short month' => ['Wed Mar 21 2018 03:42:00 GMT+0000 (Coordinated Universal Time)', '2018-03-21T03:42:00+00:00'],
    'another zone' => ['Sat Jun 13 2020 08:05:00 GMT+0200 (Central European Summer Time)', '2020-06-13T06:05:00+00:00'],
    'spelled-out month' => ['Thu January 1 2026 00:00:00 GMT+0000 (Coordinated Universal Time)', '2026-01-01T00:00:00+00:00'],
    'blank' => ['', null],
    'not a date' => ['yesterday-ish', null],
    // The weekday is not the date: a wrong one moves nothing.
    'a weekday that does not match' => ['Mon Mar 21 2018 03:42:00 GMT+0000 (Coordinated Universal Time)', '2018-03-21T03:42:00+00:00'],
    'a day the month does not have' => ['Fri Feb 30 2018 03:42:00 GMT+0000 (Coordinated Universal Time)', null],
]);

test('a WeTrakr moment comes back on the clock the instance keeps time in', function () {
    onClock('Europe/Warsaw', fn () => expect(WeTrakrArchive::date('Wed Mar 21 2018 03:42:00 GMT+0000 (Coordinated Universal Time)')?->toDateTimeString())->toBe('2018-03-21 04:42:00'));
});

test('an episode row is placed by its show and numbers, a row lacking one of them is not', function (array $row, ?array $place) {
    expect(WeTrakrArchive::place([...['type' => 'episode', 'show_title' => 'Some Show', 'season_number' => '1', 'episode_number' => '2'], ...$row]))->toBe($place);
})->with([
    'numbered' => [[], ['Some Show', 1, 2]],
    'a special' => [['season_number' => '0'], ['Some Show', 0, 2]],
    'padded' => [['season_number' => ' 3 ', 'type' => ' episode '], ['Some Show', 3, 2]],
    'no show' => [['show_title' => ''], null],
    'no number' => [['episode_number' => ''], null],
    'a movie' => [['type' => 'movie'], null],
]);

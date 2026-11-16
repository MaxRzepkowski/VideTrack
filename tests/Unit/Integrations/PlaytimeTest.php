<?php

use App\Integrations\Import\Playtime;

test('a play time reads from a clock or a count of seconds, a blank or zero as nothing', function (string $value, ?int $minutes, ?int $seconds) {
    expect(Playtime::read($value))->toBe($minutes)
        ->and(Playtime::seconds($value))->toBe($seconds);
})->with([
    'hours, minutes and seconds' => ['8:35:30', 516, 30930],
    'minutes and seconds' => ['46:30', 47, 2790],
    'seconds' => ['109215', 1820, 109215],
    'zero' => ['0', null, 0],
    'blank' => ['', null, null],
    'no time logged' => ['--', null, null],
    'more parts than a clock has' => ['1:02:03:04', null, null],
]);

test('a total in minutes reads as written; zero is never launched', function () {
    expect(Playtime::minutes('47'))->toBe(47)
        ->and(Playtime::minutes('0'))->toBeNull()
        ->and(Playtime::minutes('4.5'))->toBeNull()
        ->and(Playtime::ofSeconds(20))->toBeNull();
});

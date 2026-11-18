<?php

use App\Users\Statistics\PeriodKind;
use App\Users\Statistics\StatisticsPeriod;

test('a year runs from its first day to its last, both included', function () {
    $year = StatisticsPeriod::ofYear(2025);

    expect($year->kind)->toBe(PeriodKind::Year)
        ->and($year->year())->toBe(2025)
        ->and($year->contains('2025-01-01'))->toBeTrue()
        ->and($year->contains('2025-12-31'))->toBeTrue()
        ->and($year->contains('2024-12-31'))->toBeFalse()
        ->and($year->contains('2026-01-01'))->toBeFalse();
});

test('a range takes its two days in either order', function () {
    $range = StatisticsPeriod::between('2025-03-20', '2025-03-12');

    expect($range->from)->toBe('2025-03-12')
        ->and($range->to)->toBe('2025-03-20')
        ->and($range->year())->toBeNull()
        ->and($range->contains('2025-03-12'))->toBeTrue()
        ->and($range->contains('2025-03-21'))->toBeFalse();
});

test('the whole history holds every day', function () {
    $whole = StatisticsPeriod::whole();

    expect($whole->contains('1950-01-01'))->toBeTrue()
        ->and($whole->contains('2999-12-31'))->toBeTrue()
        ->and($whole->year())->toBeNull();
});

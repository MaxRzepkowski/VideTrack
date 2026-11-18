<?php

use App\Users\Statistics\PeriodRatings;

test('a rated title falls on the side of everyone else\'s average it was rated on, exactly on it apart', function () {
    $rated = new PeriodRatings(
        ratings: [1 => 9, 2 => 5, 3 => 7, 4 => 8, 5 => 6],
        community: [1 => 6.0, 2 => 7.5, 3 => 7.0, 4 => 8.5],
    );

    expect($rated->gaps())->toBe([1 => 3.0, 2 => -2.5, 3 => 0.0, 4 => -0.5])
        ->and($rated->higher())->toBe([1])
        ->and($rated->lower())->toBe([2, 4])
        ->and($rated->same())->toBe(1)
        ->and($rated->averageGap())->toBe(0.0);
});

test('without everyone else\'s ratings there is no gap to average', function () {
    $rated = new PeriodRatings(ratings: [1 => 9], community: []);

    expect($rated->gaps())->toBe([])
        ->and($rated->same())->toBe(0)
        ->and($rated->averageGap())->toBeNull();
});

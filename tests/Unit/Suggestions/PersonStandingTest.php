<?php

use App\Suggestions\Data\PersonStanding;

test('an average is pulled toward the user\'s own, the less the longer the record', function () {
    $twoHits = new PersonStanding('tmdb', '1', finished: 2, rated: 2, ratingSum: 20);
    $longRecord = new PersonStanding('tmdb', '2', finished: 40, rated: 40, ratingSum: 400);

    // Both average 10 against the user's 6; five imaginary average films count in.
    expect($twoHits->average())->toBe(10.0)
        ->and(round($twoHits->pulledAverage(6.0, 5), 2))->toBe(7.14)
        ->and(round($longRecord->pulledAverage(6.0, 5), 2))->toBe(9.56);
});

test('someone the user never rated stands at the user\'s own average', function () {
    $unrated = new PersonStanding('tmdb', '1', finished: 3, rated: 0, ratingSum: 0);

    expect($unrated->average())->toBe(0.0)
        ->and($unrated->pulledAverage(6.5, 5))->toBe(6.5);
});

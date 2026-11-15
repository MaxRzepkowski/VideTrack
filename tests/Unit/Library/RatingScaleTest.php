<?php

use App\Library\RatingScale;

test('stars become points and points round up to whole stars', function (int $rating, int $stars) {
    expect(RatingScale::toStars($rating))->toBe($stars);
})->with([
    'the lowest score' => [1, 1],
    'one star' => [2, 1],
    'a half star' => [3, 2],
    'four and a half' => [9, 5],
    'the highest score' => [10, 5],
]);

test('a star is two points, old and new spellings alike', function (float $stars, int $rating) {
    expect(RatingScale::fromStars($stars))->toBe($rating);
})->with([
    'one star' => [1, 2],
    'five stars' => [5.0, 10],
    'half stars from another service' => [3.5, 7],
]);

test('an even score survives the trip through stars', function () {
    foreach (range(RatingScale::MIN + 1, RatingScale::MAX, 2) as $rating) {
        expect(RatingScale::fromStars(RatingScale::toStars($rating)))->toBe($rating);
    }
});

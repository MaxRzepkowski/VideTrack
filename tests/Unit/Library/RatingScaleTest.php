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

test('stars as a file writes them read as points, nothing read as no rating', function (string $value, ?int $rating) {
    expect(RatingScale::readStars($value))->toBe($rating);
})->with([
    'a whole star' => ['4', 8],
    'one decimal' => ['4.5', 9],
    'a quarter star' => ['4.25', 9],
    'half a star, the bottom of the scale' => ['0.5', 1],
    'a sliver of a star still the bottom' => ['0.1', 1],
    'unrated as zero' => ['0', null],
    'unrated as blank' => ['', null],
    'past five stars' => ['6', null],
    'not a number' => ['great', null],
]);

test('points as a file writes them read on the scale, off it as no rating', function (string $value, ?int $rating) {
    expect(RatingScale::readPoints($value))->toBe($rating);
})->with([
    'a whole point' => ['8', 8],
    'a decimal' => ['7.0', 7],
    'rounded' => ['8.6', 9],
    'unrated' => ['0', null],
    'blank' => [' ', null],
    'past ten' => ['11', null],
]);

test('a score out of 100 lands on the nearest point, a blank, zero or overshoot on none', function (string $value, ?int $rating) {
    expect(RatingScale::readPercent($value))->toBe($rating);
})->with([
    'whole' => ['85', 9],
    'rounding down' => ['84', 8],
    'the bottom stays on the scale' => ['3', 1],
    'the top' => ['100', 10],
    'with a fraction' => ['72.5', 7],
    'unrated' => ['0', null],
    'blank' => ['', null],
    'past the top' => ['101', null],
    'not a number' => ['--', null],
]);

test('stars a service sends as a number read as points, none or off the five as no rating', function (?float $stars, ?int $rating) {
    expect(RatingScale::stars($stars))->toBe($rating);
})->with([
    'half a star' => [0.5, RatingScale::MIN],
    'four and a half' => [4.5, 9],
    'all five' => [(float) RatingScale::STARS, RatingScale::MAX],
    'none' => [null, null],
    'zero' => [0.0, null],
    'past five' => [5.5, null],
]);

test('a score out of 100 a service sends as a number lands on the nearest point, none or off the scale as no rating', function (?float $score, ?int $rating) {
    expect(RatingScale::percent($score))->toBe($rating);
})->with([
    'eighty-five' => [85.0, 9],
    'one' => [1.0, RatingScale::MIN],
    'a hundred' => [100.0, RatingScale::MAX],
    'none' => [null, null],
    'zero' => [0.0, null],
    'past a hundred' => [101.0, null],
]);

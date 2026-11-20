<?php

use App\Catalog\DatePrecision;

test('an approximate date runs to the last day it may mean, whichever day stands in for it', function (DatePrecision $precision, string $date, string $last) {
    expect($precision->lastDay($date))->toBe($last);
})->with([
    'a day is itself' => [DatePrecision::Day, '2028-03-12', '2028-03-12'],
    'a month stamped with its first day' => [DatePrecision::Month, '2027-02-01', '2027-02-28'],
    'a quarter stamped with its last day' => [DatePrecision::Quarter, '2028-03-31', '2028-03-31'],
    'a quarter stamped inside it' => [DatePrecision::Quarter, '2028-05-01', '2028-06-30'],
    'a year' => [DatePrecision::Year, '2028-12-31', '2028-12-31'],
    'a year stamped with its first day' => [DatePrecision::Year, '2028-01-01', '2028-12-31'],
]);

test('a date stays ahead while any day it may mean is still to come', function (DatePrecision $precision, string $date, string $day, bool $ahead) {
    expect($precision->isStillAhead($date, $day))->toBe($ahead);
})->with([
    'a day on itself' => [DatePrecision::Day, '2028-03-12', '2028-03-12', true],
    'a day after it' => [DatePrecision::Day, '2028-03-12', '2028-03-13', false],
    'a month stamped with its first day, inside it' => [DatePrecision::Month, '2028-03-01', '2028-03-20', true],
    'a month once it is over' => [DatePrecision::Month, '2028-03-01', '2028-04-01', false],
    'a quarter stamped inside it, on its last day' => [DatePrecision::Quarter, '2028-05-01', '2028-06-30', true],
    'a year stamped with its first day, in December' => [DatePrecision::Year, '2028-01-01', '2028-12-31', true],
    'a year once it is over' => [DatePrecision::Year, '2028-12-31', '2029-01-01', false],
]);

test('a day says more than a month, a month more than a quarter, a quarter more than a year', function () {
    $order = [DatePrecision::Day, DatePrecision::Month, DatePrecision::Quarter, DatePrecision::Year];

    foreach ($order as $i => $finer) {
        foreach ($order as $j => $other) {
            expect($finer->isFinerThan($other))->toBe($i < $j);
        }
    }
});

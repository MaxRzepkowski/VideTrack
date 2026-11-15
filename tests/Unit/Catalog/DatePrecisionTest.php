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

test('a day says more than a month, a month more than a quarter, a quarter more than a year', function () {
    $order = [DatePrecision::Day, DatePrecision::Month, DatePrecision::Quarter, DatePrecision::Year];

    foreach ($order as $i => $finer) {
        foreach ($order as $j => $other) {
            expect($finer->isFinerThan($other))->toBe($i < $j);
        }
    }
});

<?php

use App\Support\Numbers;

test('a whole number is read from a number or from digits', function (mixed $value, ?int $expected) {
    expect(Numbers::int($value))->toBe($expected);
})->with([
    'int' => [42, 42],
    'zero' => [0, 0],
    'whole float' => [3.0, 3],
    'fraction' => [3.5, null],
    'infinite' => [INF, null],
    'digits' => ['17', 17],
    'negative digits' => ['-4', -4],
    'decimal text' => ['1.5', null],
    'null' => [null, null],
    'bool' => [true, null],
]);

test('a number with a fraction is read from a number or from text', function (mixed $value, ?float $expected) {
    expect(Numbers::float($value))->toBe($expected);
})->with([
    'float' => [2.5, 2.5],
    'int' => [2, 2.0],
    'text' => ['2.25', 2.25],
    'junk' => ['two', null],
    'null' => [null, null],
]);

test('a keyed column keeps the entries whole on both sides', function () {
    expect(Numbers::intMap([603 => 8, '7' => '9', 'x' => 3, 12 => null]))->toBe([603 => 8, 7 => 9]);
});

test('a column keeps its whole numbers in order', function () {
    expect(Numbers::ints([3, '7', null, 'x', 2.0, 1.5]))->toBe([3, 7, 2])
        ->and(Numbers::ints(collect([1, 2])))->toBe([1, 2]);
});

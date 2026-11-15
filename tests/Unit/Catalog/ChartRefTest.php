<?php

use App\Catalog\ChartOrder;
use App\Catalog\Sets\ChartRef;

test('a chart\'s ref names its type, order, years and depth', function () {
    expect((new ChartRef('movie', ChartOrder::MostVoted, 100, 1980, 1989))->key('tmdb')->id())->toBe('chart:tmdb:movie:most-voted:1980-1989:100')
        ->and((new ChartRef('show', ChartOrder::TopRated, 250))->key('tmdb')->ref)->toBe('show:top-rated:all:250');
});

test('a ref reads back as the chart it names, and anything else as none', function (string $ref, ?ChartRef $chart) {
    expect(ChartRef::parse($ref))->toEqual($chart);
})->with([
    'a span of years' => ['movie:most-voted:1980-1989:100', new ChartRef('movie', ChartOrder::MostVoted, 100, 1980, 1989)],
    'of all time' => ['show:top-rated:all:250', new ChartRef('show', ChartOrder::TopRated, 250)],
    'an order nobody ranks by' => ['movie:loudest:all:10', null],
    'a year that is not one' => ['movie:most-voted:80-89:10', null],
    'no depth' => ['movie:most-voted:all', null],
    'not a ref at all' => ['nonsense', null],
]);

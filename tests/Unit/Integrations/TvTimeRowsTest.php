<?php

use App\Integrations\TvTime\TvTimeArchive;
use App\Integrations\TvTime\TvTimeFilms;

test('a film is searched by the English title its sort key carries', function (array $row, ?string $title) {
    expect(TvTimeFilms::englishTitle($row))->toBe($title);
})->with([
    'a watch' => [['type' => 'watch', 'alpha_range_key' => 'watch-alpha-space-sweepers'], 'space sweepers'],
    'a rewatch' => [['type' => 'rewatch', 'alpha_range_key' => 'rewatch-alpha-what-happened-to-monday'], 'what happened to monday'],
    'a key of another type' => [['type' => 'watch', 'alpha_range_key' => 'towatch-alpha-heretic'], null],
    'an empty slug' => [['type' => 'watch', 'alpha_range_key' => 'watch-alpha-'], null],
    'no type' => [['alpha_range_key' => 'watch-alpha-heretic'], null],
]);

test('the older table dates a viewing by the epoch in its key, else by the row', function () {
    expect(TvTimeArchive::watchedAt(['watch_date_range_key' => 'watch-date-1649590015', 'created_at' => '2022-01-01 00:00:00'])?->toDateTimeString())->toBe('2022-04-10 11:26:55')
        ->and(TvTimeArchive::watchedAt(['watch_date_range_key' => '', 'created_at' => '2021-02-06 20:00:00'])?->toDateTimeString())->toBe('2021-02-06 20:00:00')
        ->and(TvTimeArchive::watchedAt(['watch_date_range_key' => 'watch-date-soon']))->toBeNull();
});

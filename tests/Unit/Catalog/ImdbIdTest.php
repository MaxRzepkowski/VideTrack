<?php

use App\Catalog\ImdbId;

test('an IMDb id reads the same bare, without its prefix or as the title page', function (string $value, ?string $id) {
    expect(ImdbId::read($value))->toBe($id);
})->with([
    'bare' => ['tt0092794', 'tt0092794'],
    'digits only' => ['92794', 'tt92794'],
    'title page' => ['http://www.imdb.com/title/tt0092794/', 'tt0092794'],
    'mobile page, upper case' => ['https://m.imdb.com/title/TT0092794', 'tt0092794'],
    'a person is no title' => ['https://www.imdb.com/name/nm0000158/', null],
    'blank' => ['', null],
    'zero' => ['0', null],
]);

<?php

use App\Catalog\Providers\Igdb\AgeRatingBoard;

test('the chip is the board of the viewer country, then the common boards, then any', function (array $ratings, string $country, ?string $chip) {
    expect(AgeRatingBoard::chip($ratings, $country))->toBe($chip);
})->with([
    'a European country reads PEGI' => [['PEGI' => '12', 'ESRB' => 'E10+'], 'PL', 'PEGI 12'],
    'Germany has a board of its own' => [['PEGI' => '12', 'USK' => '12', 'ESRB' => 'E10+'], 'DE', 'USK 12'],
    'a North American country reads ESRB' => [['PEGI' => '12', 'ESRB' => 'E10+'], 'us', 'ESRB E10+'],
    'without its own board, PEGI first' => [['ESRB' => 'T', 'PEGI' => '16'], 'JP', 'PEGI 16'],
    'without the common boards, whatever was rated' => [['GRAC' => '15'], 'AR', 'GRAC 15'],
    'nothing rated' => [[], 'PL', null],
]);

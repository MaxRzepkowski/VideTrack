<?php

use App\Integrations\Anime\AnimeMapping;

test('an entry\'s episode lands past the episodes the entry starts after', function () {
    $secondCour = new AnimeMapping(tvdbId: 267440, tvdbSeason: 3, episodeOffset: 12, imdbIds: []);

    expect($secondCour->episode(1))->toBe([3, 13])
        ->and($secondCour->episode(10))->toBe([3, 22])
        ->and((new AnimeMapping(267440, 1, 0, []))->episode(1))->toBe([1, 1]);
});

test('an entry outside a regular TVDB season is not placed by episode', function (AnimeMapping $mapping) {
    $mapping->episode(1);
})->throws(LogicException::class)->with([
    'a film' => fn () => new AnimeMapping(tvdbId: null, tvdbSeason: null, episodeOffset: 0, imdbIds: ['tt0119698']),
    'a special, in season 0' => fn () => new AnimeMapping(tvdbId: 267440, tvdbSeason: 0, episodeOffset: 0, imdbIds: []),
]);

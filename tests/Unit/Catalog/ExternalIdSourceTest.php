<?php

use App\Catalog\ExternalIdSource;

test('a bag of ids narrows to the sources asked for, whatever else the sender put in it', function () {
    expect(ExternalIdSource::only(['tmdb' => '603', 'imdb' => 'tt0133093', 'tvdb' => '', 'anidb' => '1'], ExternalIdSource::Imdb, ExternalIdSource::Tvdb))
        ->toBe(['imdb' => 'tt0133093', 'tvdb' => '']);
});

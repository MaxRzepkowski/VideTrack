<?php

use App\Catalog\Data\MediaSummary;

test('a stored bag keeps what fits the card and drops a named key of another kind', function () {
    expect(MediaSummary::extraFromStored([
        'seasonNumber' => 2,
        'showTitle' => 'Severance',
        'showPoster' => null,
        'episodeCount' => null,
        'gameType' => 'dlc',
        'authors' => ['Ursula K. Le Guin'],
        // The provider's own keys ride along untouched.
        'budget' => 1000,
        'networks' => [['name' => 'HBO']],
    ]))->toBe([
        'seasonNumber' => 2,
        'showTitle' => 'Severance',
        'showPoster' => null,
        'episodeCount' => null,
        'gameType' => 'dlc',
        'authors' => ['Ursula K. Le Guin'],
        'budget' => 1000,
        'networks' => [['name' => 'HBO']],
    ]);
});

test('a named key of another shape does not reach the card', function () {
    expect(MediaSummary::extraFromStored([
        'seasonNumber' => '2',
        'showTitle' => null,
        'gameType' => 'main_game',
        'episodeBadge' => 'season_opener',
        'authors' => ['Le Guin', 7],
        'knownFor' => 'Acting',
        'itemCount' => 3.5,
        0 => 'a list item',
        'kept' => 'x',
    ]))->toBe(['kept' => 'x']);
});

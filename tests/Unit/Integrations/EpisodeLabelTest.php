<?php

use App\Integrations\EpisodeLabel;

test('an episode reads as its show and place, the show alone when the place is unknown', function () {
    expect(EpisodeLabel::of('Breaking Bad', 1, 2))->toBe('Breaking Bad S01E02')
        ->and(EpisodeLabel::of('One Piece', 21, 1100))->toBe('One Piece S21E1100')
        ->and(EpisodeLabel::of('Breaking Bad', null, 2))->toBe('Breaking Bad');
});

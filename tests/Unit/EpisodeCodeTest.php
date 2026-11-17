<?php

use App\Catalog\EpisodeCode;

test("an episode's place is its season and number, two digits each at least", function () {
    expect(EpisodeCode::of(2, 9))->toBe('S02E09')
        ->and(EpisodeCode::of(0, 1))->toBe('S00E01')
        ->and(EpisodeCode::of(12, 104))->toBe('S12E104');
});

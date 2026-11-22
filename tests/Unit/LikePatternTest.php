<?php

use App\Support\LikePattern;

test('a typed percent sign, underscore or backslash is matched as itself', function () {
    expect(LikePattern::containing('100% Wolf'))->toBe('%100\% Wolf%')
        ->and(LikePattern::containing('snake_case'))->toBe('%snake\_case%')
        ->and(LikePattern::startingWith('C:\\Games'))->toBe('C:\\\\Games%');
});

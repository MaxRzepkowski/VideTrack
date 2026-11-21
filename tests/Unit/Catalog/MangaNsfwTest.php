<?php

use App\Catalog\MatureContent;
use App\Catalog\Providers\Mal\MangaNsfw;

test('only what MAL rates not safe for work is adult material; the suggestive stays in', function () {
    expect(MangaNsfw::Black->isAdult())->toBeTrue()
        ->and(MangaNsfw::Gray->isAdult())->toBeFalse()
        ->and(MangaNsfw::White->isAdult())->toBeFalse();
});

test('a viewer level lets in the ratings up to it', function (MatureContent $mature, array $allowed) {
    $shown = array_values(array_filter(MangaNsfw::cases(), fn (MangaNsfw $rating): bool => $rating->allowedAt($mature)));

    expect($shown)->toBe($allowed);
})->with([
    'safe' => [MatureContent::Safe, [MangaNsfw::White]],
    'suggestive' => [MatureContent::Suggestive, [MangaNsfw::White, MangaNsfw::Gray]],
    'adult' => [MatureContent::Adult, [MangaNsfw::White, MangaNsfw::Gray, MangaNsfw::Black]],
]);

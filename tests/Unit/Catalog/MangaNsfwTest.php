<?php

use App\Catalog\Providers\Mal\MangaNsfw;

test('only what MAL rates not safe for work is adult material; the suggestive stays in', function () {
    expect(MangaNsfw::Black->isAdult())->toBeTrue()
        ->and(MangaNsfw::Gray->isAdult())->toBeFalse()
        ->and(MangaNsfw::White->isAdult())->toBeFalse();
});

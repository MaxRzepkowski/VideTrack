<?php

use App\Support\StoredTime;
use Carbon\CarbonImmutable;

test('a moment told in any zone comes back in the stored zone as the same instant', function () {
    $warsaw = CarbonImmutable::parse('2026-03-02 00:30', 'Europe/Warsaw');
    $stored = StoredTime::of($warsaw);

    expect($stored->timezoneName)->toBe(config()->string('app.timezone'))
        ->and($stored->equalTo($warsaw))->toBeTrue();
});

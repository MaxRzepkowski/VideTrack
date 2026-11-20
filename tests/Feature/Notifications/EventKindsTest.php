<?php

use App\Notifications\Events\EventKinds;
use App\Notifications\MediaEventType;

test('every kind of media event has the class that builds and words it', function () {
    $kinds = app(EventKinds::class);

    expect(array_values(array_filter(MediaEventType::cases(), fn (MediaEventType $type): bool => $kinds->of($type) === null)))->toBe([]);
});

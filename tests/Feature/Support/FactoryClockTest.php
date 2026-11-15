<?php

use App\Models\MediaItem;

test('a made-up title has aired by the test clock, wherever that clock stands', function () {
    // Far behind the machine's own date: a release date drawn from the real
    // time would land in this clock's future.
    $this->travelTo('2020-01-01 12:00');

    $titles = MediaItem::factory()->count(200)->make();

    expect($titles->reject(fn (MediaItem $title): bool => $title->hasAired(today()))->all())->toBe([]);
});

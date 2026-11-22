<?php

use App\Notifications\Copy\CopyFormat;
use App\Notifications\Data\ValueChange;

test('a moved value reads old to new as one fact, the new one alone when there was none, nothing without a change', function () {
    $format = new CopyFormat;

    expect($format->change(new ValueChange(from: 'Returning', to: 'Ended')))->toBe(['Returning → Ended'])
        ->and($format->change(new ValueChange(from: null, to: 'Ended')))->toBe(['Ended'])
        ->and($format->change(null))->toBe([]);
});

<?php

use App\Library\PlaybackCompletion;

test('a playback counts from the completion threshold of the runtime on', function () {
    $runtime = 100;
    $threshold = PlaybackCompletion::threshold();
    $atThreshold = intdiv($runtime * 60 * $threshold, 100);

    expect(PlaybackCompletion::covers($atThreshold, $runtime))->toBeTrue()
        ->and(PlaybackCompletion::covers($atThreshold - 1, $runtime))->toBeFalse()
        ->and(PlaybackCompletion::share($atThreshold, $runtime))->toBe((float) $threshold);
});

test('a runtime of nothing is covered by no playback', function () {
    expect(PlaybackCompletion::covers(10_000, 0))->toBeFalse()
        ->and(PlaybackCompletion::share(10_000, 0))->toBe(0.0);
});

test('the threshold is the one set for incoming scrobbles', function () {
    config(['tracker.scrobble.completion_threshold' => 90]);

    expect(PlaybackCompletion::reached(89.9))->toBeFalse()
        ->and(PlaybackCompletion::reached(90.0))->toBeTrue();
});

<?php

namespace App\Library;

/**
 * When a playback that stopped counts as watched. A media server reports a
 * stop, not a verdict: credits, a skipped outro or simply closing the app
 * all arrive the same way, so a stop past the threshold is a finished
 * viewing, a stop before it is not.
 */
final class PlaybackCompletion
{
    private const SECONDS_PER_MINUTE = 60;

    private const PERCENT = 100;

    /** The share of the runtime, in percent, a stop has to reach. */
    public static function threshold(): int
    {
        return config()->integer('tracker.scrobble.completion_threshold');
    }

    public static function reached(float $percent): bool
    {
        return $percent >= self::threshold();
    }

    /** Whether $seconds of playback cover a runtime of $minutes. */
    public static function covers(int $seconds, int $minutes): bool
    {
        return $minutes > 0 && self::reached(self::share($seconds, $minutes));
    }

    /** How much of a runtime of $minutes $seconds of playback cover, in percent. */
    public static function share(int $seconds, int $minutes): float
    {
        return $minutes > 0 ? $seconds / ($minutes * self::SECONDS_PER_MINUTE) * self::PERCENT : 0.0;
    }
}

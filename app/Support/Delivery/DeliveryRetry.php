<?php

namespace App\Support\Delivery;

/**
 * Whether a message the receiver did not take is worth another try from the
 * queue, and after how long. Every job that delivers a message asks here,
 * so a receiver is waited out the same way whichever kind it is.
 */
final class DeliveryRetry
{
    /** How many times the queue runs one delivery at most. */
    public const TRIES = 5;

    /** Seconds before the second and the third try, when the receiver was not there to answer. */
    public const UNAVAILABLE_AFTER = [60, 300];

    private const UNAVAILABLE_TRIES = 3;

    /**
     * A throttled message comes back once the receiver's window (a few
     * seconds for a chat webhook) has passed, spread so a burst of the day's
     * releases does not return all at once and hit the limit again.
     */
    private const THROTTLED_DELAY_SECONDS = 5;

    private const THROTTLED_SPREAD_SECONDS = 10;

    /** Seconds until the next try after this answer on this attempt (from 1), or null when the answer stands. */
    public static function after(DeliveryAnswer $answer, int $attempt): ?int
    {
        return match (true) {
            $answer === DeliveryAnswer::Throttled && $attempt < self::TRIES => self::THROTTLED_DELAY_SECONDS + random_int(0, self::THROTTLED_SPREAD_SECONDS),
            $answer === DeliveryAnswer::Unavailable && $attempt < self::UNAVAILABLE_TRIES => self::UNAVAILABLE_AFTER[$attempt - 1] ?? 0,
            default => null,
        };
    }
}

<?php

namespace App\Library;

/** The scale a score is given on — one place for the requests, the scrobble webhooks and the imports. */
final class RatingScale
{
    public const MIN = 1;

    public const MAX = 10;

    /** The stars a five-star service gives. */
    public const STARS = 5;

    /** The top of a score out of a hundred. */
    private const PERCENT = 100;

    public static function holds(int $rating): bool
    {
        return $rating >= self::MIN && $rating <= self::MAX;
    }

    /**
     * A score in stars as a file writes it ("4.5", "5.0", quarter stars too);
     * null for a blank, a zero (most services' "unrated") or anything off
     * the five stars. The smallest fraction is the bottom of the scale.
     */
    public static function readStars(string $value): ?int
    {
        $value = trim($value);

        return is_numeric($value) ? self::stars((float) $value) : null;
    }

    /**
     * A score in stars a service sends as a number (4.5); null for none, a
     * zero (unrated) or anything off the five stars.
     */
    public static function stars(?float $stars): ?int
    {
        if ($stars === null || $stars <= 0 || $stars > self::STARS) {
            return null;
        }

        return max(self::MIN, self::fromStars($stars));
    }

    /** A score already on this scale as a file writes it ("8", "7.0"); null when it is off the scale. */
    public static function readPoints(string $value): ?int
    {
        $value = trim($value);

        if (! is_numeric($value)) {
            return null;
        }

        $rating = (int) round((float) $value);

        return self::holds($rating) ? $rating : null;
    }

    /**
     * A score out of 100 as a file writes it ("85"), to the nearest point
     * and never below the bottom of the scale; null for a blank, a zero
     * (unrated) or anything past 100.
     */
    public static function readPercent(string $value): ?int
    {
        $value = trim($value);

        if (! is_numeric($value) || (float) $value <= 0 || (float) $value > self::PERCENT) {
            return null;
        }

        return max(self::MIN, (int) round((float) $value * self::MAX / self::PERCENT));
    }

    /** A score of 1–5 stars on this scale: each star is two points. */
    public static function fromStars(float $stars): int
    {
        return (int) round($stars * (self::MAX / self::STARS));
    }

    /**
     * A score as whole stars, the half points rounded up: 1 and 2 are one
     * star, 9 and 10 five. Read back with {@see fromStars()}, an even score
     * returns unchanged.
     */
    public static function toStars(int $rating): int
    {
        return (int) ceil($rating / (self::MAX / self::STARS));
    }
}

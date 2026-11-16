<?php

namespace App\Library;

/** The scale a score is given on — one place for the requests, the scrobble webhooks and the imports. */
final class RatingScale
{
    public const MIN = 1;

    public const MAX = 10;

    /** The stars a five-star service gives. */
    public const STARS = 5;

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

        if (! is_numeric($value) || (float) $value <= 0 || (float) $value > self::STARS) {
            return null;
        }

        return max(self::MIN, self::fromStars((float) $value));
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

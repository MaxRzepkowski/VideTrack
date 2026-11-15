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

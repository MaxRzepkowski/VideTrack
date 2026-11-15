<?php

namespace App\Suggestions\Data;

use App\Catalog\Sets\SetKey;
use App\Catalog\Sets\SetKind;

/**
 * Where a user stands with one person's work in a craft, from the titles
 * stored here: how many they finished, and how they rated them.
 */
final readonly class PersonStanding
{
    public function __construct(
        public string $provider,
        public string $ref,
        public int $finished,
        public int $rated,
        public int $ratingSum,
    ) {}

    public function filmography(): SetKey
    {
        return new SetKey(SetKind::Filmography, $this->provider, $this->ref);
    }

    /**
     * The person's average rating pulled toward the user's own: `$priorFilms`
     * imaginary films at the user's average count in, so a couple of lucky
     * hits weigh little and a long record weighs fully.
     */
    public function pulledAverage(float $ownAverage, int $priorFilms): float
    {
        return ($this->ratingSum + $priorFilms * $ownAverage) / ($this->rated + $priorFilms);
    }

    public function average(): float
    {
        return $this->rated === 0 ? 0.0 : $this->ratingSum / $this->rated;
    }
}

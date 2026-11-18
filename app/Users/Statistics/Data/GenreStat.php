<?php

namespace App\Users\Statistics\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A genre of a period's titles: how many, how much time (or the type's unit) and how they were rated. */
#[TypeScript]
final readonly class GenreStat
{
    public function __construct(
        public string $name,
        public int $titles,
        public int $amount,
        public ?float $averageRating,
        /** How many of its titles the average rests on. */
        public int $rated,
    ) {}
}

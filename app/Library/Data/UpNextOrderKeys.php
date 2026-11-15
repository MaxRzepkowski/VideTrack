<?php

namespace App\Library\Data;

/** What an in-progress shelf entry is ordered by, whichever order the user picked. */
final readonly class UpNextOrderKeys
{
    /**
     * @param  list<int|float>|null  $remaining
     */
    public function __construct(
        /** How much is left, compared in order; null sorts last (nothing to measure it by). */
        public ?array $remaining,
        /** The release the entry waits on, YYYY-MM-DD…; null sorts last. */
        public ?string $released,
        /** The name the reader sees on the card. */
        public string $title,
        /** When the user last touched it: what every tie falls back to. */
        public ?string $activity,
    ) {}
}

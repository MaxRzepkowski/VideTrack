<?php

namespace App\Library\Data;

use App\Catalog\Data\MediaSummary;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A title of a progress type (a game, a book) the user is in the middle of:
 * the "Currently playing" and "Currently reading" cards.
 */
#[TypeScript]
final readonly class PlayingEntry
{
    public function __construct(
        /** Carries the counter's total, where the type has one. */
        public MediaSummary $media,
        /** How far the counter got, in the type's unit (games: session minutes). */
        public ?int $progress,
        /** The part still ahead, from 0 to 1, where the total is known. */
        public ?float $remainingShare,
        /** When the attempt last moved. */
        public ?string $at,
    ) {}
}

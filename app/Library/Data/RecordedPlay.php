<?php

namespace App\Library\Data;

use App\Enums\PlayOutcome;
use App\Models\Play;

/** One closed attempt as an event announces it: completed or dropped, when, and what it cost. */
final readonly class RecordedPlay
{
    public function __construct(
        public int $playId,
        public int $itemId,
        public string $watchedAt,
        public PlayOutcome $outcome,
        /** The attempt's cost in the type's progress unit (games: minutes, books: pages). */
        public ?int $amount,
    ) {}

    public static function of(Play $play): self
    {
        return new self(
            playId: $play->id,
            itemId: $play->media_item_id,
            // toISOString()'s own shape, which may not be null: every play is dated.
            watchedAt: $play->watched_at->toImmutable()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            outcome: $play->outcome,
            amount: $play->amount,
        );
    }
}

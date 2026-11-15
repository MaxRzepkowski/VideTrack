<?php

namespace App\Http\Responses;

use App\Catalog\MediaItemSummaries;
use App\Library\AffectedStates;
use App\Models\MediaItem;
use App\Models\User;

/**
 * The answer to a write on a position, built one way for every write: the
 * position's card in its context — an episode names its show and season, as
 * the library lists it — and the states the write moved.
 */
final readonly class PositionWrites
{
    public function __construct(
        private AffectedStates $affected,
        private MediaItemSummaries $cards,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function of(User $viewer, MediaItem $item, array $meta = [], int $status = 200): PositionWritten
    {
        $item->loadMissing('parent.parent');

        return new PositionWritten($this->cards->inContext($item), $this->affected->of($viewer, $item), $meta, $status);
    }
}

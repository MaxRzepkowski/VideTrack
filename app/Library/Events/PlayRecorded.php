<?php

namespace App\Library\Events;

use App\Library\Data\RecordedPlay;
use App\Library\MutationSource;
use App\Models\MediaItem;
use App\Models\Play;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Attempts closed — completed, or dropped. One event carries the whole batch:
 * marking a show watched settles every unwatched episode, and a consumer
 * wants one delivery with a list, not four hundred pings.
 *
 * What closing an attempt settles travels with it, not as events of its own:
 * the open state it ends (a pause, sessions in progress) and, for a viewing,
 * the drops above it that it lifts. A consumer reads the positions' state
 * after this event. Removing history is different — {@see PlayRemoved} and the
 * statuses an unmark takes away are announced one by one.
 */
final readonly class PlayRecorded implements ShouldDispatchAfterCommit
{
    /**
     * @param  string  $itemType  type of the position that was acted on, not of the plays
     * @param  list<RecordedPlay>  $plays
     */
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public int $itemId,
        public string $itemType,
        public int $rootId,
        public array $plays,
    ) {}

    /**
     * @param  list<Play>  $plays  closed on $item or below it
     */
    public static function of(int $userId, MutationSource $source, MediaItem $item, MediaItem $root, array $plays): self
    {
        return new self(
            userId: $userId,
            source: $source,
            itemId: $item->id,
            itemType: $item->type,
            rootId: $root->id,
            plays: array_map(RecordedPlay::of(...), $plays),
        );
    }
}

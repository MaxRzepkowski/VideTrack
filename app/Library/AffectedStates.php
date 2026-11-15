<?php

namespace App\Library;

use App\Catalog\Hierarchy;
use App\Catalog\MediaItemSummaries;
use App\Library\Data\UserMediaState;
use App\Models\MediaItem;
use App\Models\User;

/**
 * What a write on one position changed for the user: the position itself and
 * every container above it. Marking an episode moves its season's and its
 * show's counters too, so the answer to that write carries all three — a
 * client holding any of them is current without asking again.
 */
final readonly class AffectedStates
{
    public function __construct(
        private LibraryStates $states,
        private Hierarchy $hierarchy,
    ) {}

    /**
     * @return array<string, UserMediaState> "type:ref" => state, the position first
     */
    public function of(User $user, MediaItem $item): array
    {
        return $this->states->resolve($user, array_map(
            fn (MediaItem $node) => MediaItemSummaries::summary($node),
            $this->hierarchy->ancestry($item),
        ));
    }
}

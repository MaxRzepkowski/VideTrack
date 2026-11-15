<?php

namespace App\Library\Events;

use App\Catalog\Creators\CreatorKind;
use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A followed creator rang its bell on titles of its own for the user: these
 * titles got a bell they did not have. The user did not ring them — the
 * creator they follow did — so this is not a FollowChanged.
 */
final readonly class CreatorTitlesBacked implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int>  $itemIds  the titles that got a bell
     */
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public CreatorKind $creatorKind,
        public int $creatorId,
        public array $itemIds,
    ) {}
}

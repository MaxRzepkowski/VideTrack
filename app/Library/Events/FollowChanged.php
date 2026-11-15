<?php

namespace App\Library\Events;

use App\Catalog\Creators\CreatorKind;
use App\Enums\FollowMode;
use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The user rang or silenced a bell: on a title (`itemId`) or on a creator
 * (`creatorKind` + `creatorId`). `mode` null means the bell went back to
 * whatever the tracking matrix says. The bells a followed creator puts on its
 * titles are not the user's intent and are announced apart
 * ({@see CreatorTitlesBacked}, {@see CreatorBackingWithdrawn}).
 */
final readonly class FollowChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $userId,
        public MutationSource $source,
        public ?FollowMode $mode,
        public ?FollowMode $previousMode,
        public ?int $itemId = null,
        public ?CreatorKind $creatorKind = null,
        public ?int $creatorId = null,
    ) {}
}

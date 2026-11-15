<?php

namespace App\Library\Events;

use App\Catalog\Creators\CreatorKind;
use App\Library\MutationSource;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A creator stopped backing titles — unfollowed, gone from the provider, or
 * the titles no longer its own — and `removed` bells nobody else backed went
 * with it. A mute and the user's own bell stay.
 */
final readonly class CreatorBackingWithdrawn implements ShouldDispatchAfterCommit
{
    public function __construct(
        /** Null: the creator was withdrawn for everyone (a collection gone). */
        public ?int $userId,
        public MutationSource $source,
        public CreatorKind $creatorKind,
        public int $creatorId,
        public int $removed,
    ) {}
}

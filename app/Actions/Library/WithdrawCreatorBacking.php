<?php

namespace App\Actions\Library;

use App\Catalog\Creators\CreatorKind;
use App\Library\Events\CreatorBackingWithdrawn;
use App\Library\MutationContext;
use App\Library\Sponsorships;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * A creator stops backing titles — for one user or for everyone, for some
 * titles or all of them ({@see Sponsorships}).
 */
final readonly class WithdrawCreatorBacking
{
    public function __construct(
        private Sponsorships $sponsorships,
        private MutationContext $mutations,
    ) {}

    /**
     * @param  list<int>|null  $itemIds  null: every title the creator backs
     * @return int bells removed
     */
    public function execute(CreatorKind $kind, int $creatorId, ?User $user = null, ?array $itemIds = null): int
    {
        $removed = $this->sponsorships->withdraw($kind, $creatorId, $user, $itemIds);

        if ($removed > 0) {
            Event::dispatch(new CreatorBackingWithdrawn($user?->id, $this->mutations->current(), $kind, $creatorId, $removed));
        }

        return $removed;
    }
}

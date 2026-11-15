<?php

namespace App\Actions\Library;

use App\Catalog\Creators\CreatorKind;
use App\Library\Events\CreatorTitlesBacked;
use App\Library\MutationContext;
use App\Library\Sponsorships;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * A followed creator backs its titles for the user ({@see Sponsorships}): a
 * title without a bell gets one, a bell another creator backs gains this one.
 */
final readonly class BackCreatorTitles
{
    public function __construct(
        private Sponsorships $sponsorships,
        private MutationContext $mutations,
    ) {}

    /**
     * @param  list<int>  $itemIds
     * @return list<int> the titles that got a bell of their own
     */
    public function execute(User $user, CreatorKind $kind, int $creatorId, array $itemIds): array
    {
        $backed = $this->sponsorships->sponsor($user, $kind, $creatorId, $itemIds);

        if ($backed !== []) {
            Event::dispatch(new CreatorTitlesBacked($user->id, $this->mutations->current(), $kind, $creatorId, $backed));
        }

        return $backed;
    }
}

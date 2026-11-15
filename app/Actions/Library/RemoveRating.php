<?php

namespace App\Actions\Library;

use App\Library\Events\RatingRemoved;
use App\Library\MutationContext;
use App\Models\MediaItem;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Support\Facades\Event;

final readonly class RemoveRating
{
    public function __construct(
        private MutationContext $mutations,
    ) {}

    public function execute(User $user, MediaItem $item): void
    {
        $deleted = Rating::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $item->id)
            ->delete();

        // Clearing a rating that was never there is not a fact.
        if ($deleted === 0) {
            return;
        }

        Event::dispatch(new RatingRemoved(
            userId: $user->id,
            source: $this->mutations->current(),
            itemId: $item->id,
        ));
    }
}

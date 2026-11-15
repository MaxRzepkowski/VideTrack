<?php

namespace App\Actions\Library;

use App\Library\Data\ListTarget;
use App\Library\Events\ListCreated;
use App\Library\Events\ListItemAdded;
use App\Library\ListPositions;
use App\Library\MutationContext;
use App\Models\ListItem;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Copies a viewable list (positions and manual order included) into the
 * current user's account as a custom list with the default visibility —
 * duplicating someone else's public list or the system watchlist yields
 * a regular own list.
 */
final readonly class DuplicateList
{
    public function __construct(
        private ListPositions $positions,
        private MutationContext $mutations,
    ) {}

    public function execute(User $user, MediaList $source): MediaList
    {
        return DB::transaction(function () use ($user, $source): MediaList {
            /** @var MediaList $copy */
            $copy = $user->lists()->create([
                'title' => __('media.title_copy', ['title' => $source->displayTitle()]),
                'description' => $source->description,
                'position' => $this->positions->nextListPosition($user),
            ]);

            $copy->items()->createMany($source->items->map(fn (ListItem $item) => [
                'media_item_id' => $item->media_item_id,
                'person_id' => $item->person_id,
                'company_id' => $item->company_id,
                'position' => $item->position,
            ]));

            // The copy is a list made and filled by the user, as if by hand.
            $origin = $this->mutations->current();
            Event::dispatch(new ListCreated(userId: $user->id, source: $origin, listId: $copy->id));

            foreach ($source->items as $item) {
                Event::dispatch(new ListItemAdded(userId: $user->id, source: $origin, listId: $copy->id, target: ListTarget::ofRow($item)));
            }

            return $copy;
        });
    }
}

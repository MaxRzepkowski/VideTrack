<?php

namespace App\Actions\Library;

use App\Library\Data\ListTarget;
use App\Library\Events\ListItemRemoved;
use App\Library\MutationContext;
use App\Models\ListItem;
use App\Models\MediaList;
use Illuminate\Support\Facades\Event;

/**
 * Takes one entry off a list. Permission to touch the list is settled by the
 * caller; this is the write, and the only place it is announced.
 */
final readonly class RemoveListItem
{
    public function __construct(
        private MutationContext $mutations,
    ) {}

    public function execute(MediaList $list, ListItem $item): void
    {
        $target = ListTarget::ofRow($item);

        $item->delete();

        // Ownerless system lists (materialized collections) belong to nobody,
        // so there is no user whose change this would be.
        if ($list->user_id === null) {
            return;
        }

        Event::dispatch(new ListItemRemoved(
            userId: $list->user_id,
            source: $this->mutations->current(),
            listId: $list->id,
            target: $target,
        ));
    }
}

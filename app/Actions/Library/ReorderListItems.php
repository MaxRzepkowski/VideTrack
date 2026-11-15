<?php

namespace App\Actions\Library;

use App\Library\Events\ListItemsReordered;
use App\Library\ListPositions;
use App\Library\MutationContext;
use App\Models\MediaList;
use Illuminate\Support\Facades\Event;

/**
 * Applies the manual drag & drop order: the given list-item ids take
 * positions 1..n; ids from other lists are ignored, items missing from the
 * payload keep their relative order after the reordered block.
 */
final readonly class ReorderListItems
{
    public function __construct(
        private ListPositions $positions,
        private MutationContext $mutations,
    ) {}

    /**
     * @param  list<int>  $itemIds
     */
    public function execute(MediaList $list, array $itemIds): void
    {
        $items = $list->items->keyBy('id');
        $positions = [];

        foreach ($this->positions->ordered($items, $itemIds) as $index => $item) {
            $positions[$item->id] = $index + 1;
        }

        $this->positions->save($items, $positions);

        if ($list->user_id !== null) {
            Event::dispatch(new ListItemsReordered(userId: $list->user_id, source: $this->mutations->current(), listId: $list->id));
        }
    }
}

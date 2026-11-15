<?php

namespace App\Actions\Library;

use App\Library\Events\ListsReordered;
use App\Library\ListPositions;
use App\Library\MutationContext;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * Applies the manual order of the lists index: the given list ids take
 * positions 1..n; ids of other users' lists are ignored, lists missing from
 * the payload keep their relative order after the reordered block.
 *
 * System lists take no part in it. The watchlist is not user content — it
 * leads the index by definition, so it is pinned to position 0 and a payload
 * naming it is simply ignored: the order the user arranges is the order of
 * their own lists.
 */
final readonly class ReorderLists
{
    public function __construct(
        private ListPositions $positions,
        private MutationContext $mutations,
    ) {}

    /**
     * @param  list<int>  $listIds
     */
    public function execute(User $user, array $listIds): void
    {
        $lists = $user->lists()->orderBy('position')->orderBy('id')->get()->keyBy('id');

        $positions = $lists->filter(fn (MediaList $list) => $list->isSystem())->mapWithKeys(fn (MediaList $list): array => [$list->id => 0])->all();

        $custom = $lists->reject(fn (MediaList $list) => $list->isSystem());

        foreach ($this->positions->ordered($custom, $listIds) as $index => $list) {
            $positions[$list->id] = $index + 1;
        }

        $this->positions->save($lists, $positions);

        Event::dispatch(new ListsReordered(userId: $user->id, source: $this->mutations->current()));
    }
}

<?php

namespace App\Http\Responses;

use App\Catalog\MediaItemSummaries;
use App\Library\Data\ListAddition;
use App\Library\LibraryStates;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;

/**
 * The answer to adding many titles to a list, built beside the write as
 * {@see PositionWrites} is for one title, so the controller picks nothing
 * out of the write's result itself. A title's place on a list moves no
 * state above it, so the states are those of the titles alone.
 */
final readonly class ListAdditionWrites
{
    public function __construct(private LibraryStates $states) {}

    /**
     * @param  list<ListAddition>  $additions
     */
    public function of(User $viewer, MediaList $list, array $additions): ListAdditionsWritten
    {
        $onList = array_values(array_filter(array_map(fn (ListAddition $addition): ?MediaItem => $addition->item, $additions)));

        return new ListAdditionsWritten(
            $additions,
            $list->items()->count(),
            $this->states->resolve($viewer, array_map(MediaItemSummaries::summary(...), $onList)),
        );
    }
}

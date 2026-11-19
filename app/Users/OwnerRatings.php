<?php

namespace App\Users;

use App\Models\MediaItem;
use App\Models\User;
use App\Support\Numbers;
use App\Users\Data\ActivityFilters;

/**
 * The panel owner's ratings of the cards on a page, for a visitor while a
 * rating orders it: sorted by the owner's, it is what the order follows;
 * sorted by their own (on the poster), it is what they compare against.
 * The owner reads theirs on the posters; the ratings panel brings its own.
 */
final readonly class OwnerRatings
{
    /** Whether the cards of the page carry the owner's rating underneath instead of a date. */
    public function shown(PanelReader $reader, ActivityFilters $filters): bool
    {
        return $reader === PanelReader::Visitor && $filters->byRating();
    }

    /**
     * @param  iterable<MediaItem>  $items
     * @return array<int, int> item id => the owner's rating
     */
    public function shownUnder(User $owner, PanelReader $reader, ActivityFilters $filters, iterable $items): array
    {
        if (! $this->shown($reader, $filters)) {
            return [];
        }

        $ids = [];

        foreach ($items as $item) {
            $ids[] = $item->id;
        }

        return Numbers::intMap($owner->ratings()->whereIn('media_item_id', $ids)->pluck('rating', 'media_item_id'));
    }
}

<?php

namespace App\Library;

use App\Library\Data\PositionMarks;
use App\Models\Favorite;
use App\Models\Follow;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\Rating;
use App\Models\User;
use App\Support\Numbers;
use App\Tracking\TrackingFactsQuery;
use App\Tracking\TrackingSource;
use Illuminate\Support\Collection;

/** Reads, one query per kind, what a user put on a set of persisted positions. */
final readonly class PositionMarksQuery
{
    public function __construct(private TrackingFactsQuery $tracking) {}

    /**
     * @param  Collection<int, MediaItem>  $items
     */
    public function of(User $user, Collection $items): PositionMarks
    {
        $itemIds = $items->map(fn (MediaItem $item): int => $item->id)->values();

        return new PositionMarks(
            ratings: Numbers::intMap(Rating::query()
                ->where('user_id', $user->id)
                ->whereIn('media_item_id', $itemIds)
                ->pluck('rating', 'media_item_id')),
            favorites: array_fill_keys(Numbers::ints(Favorite::query()
                ->where('user_id', $user->id)
                ->whereIn('media_item_id', $itemIds)
                ->pluck('media_item_id')), true),
            listIds: $this->listIds($user, $itemIds),
            // Bell exceptions (follow / mute) — only hierarchy roots carry
            // them. A row materialized by a followed person or company
            // carries that sponsor's name, so the bell can say WHY and offer
            // muting instead of a futile unfollow.
            follows: $this->follows($user, $itemIds),
            followSources: $this->followSources($user, $items),
        );
    }

    /**
     * @param  Collection<int, int>  $itemIds
     * @return array<int, Follow> item id => the bell's exception on it
     */
    private function follows(User $user, Collection $itemIds): array
    {
        $rows = Follow::query()
            ->where('user_id', $user->id)
            ->whereIn('media_item_id', $itemIds)
            ->with(['viaPeople', 'viaCompanies', 'viaLists'])
            ->get();
        $follows = [];

        foreach ($rows as $follow) {
            // A title's bell always names its item; the column is nullable for a creator's.
            if ($follow->media_item_id !== null) {
                $follows[$follow->media_item_id] = $follow;
            }
        }

        return $follows;
    }

    /**
     * @param  Collection<int, int>  $itemIds
     * @return array<int, list<int>> item id => the user's lists holding it
     */
    private function listIds(User $user, Collection $itemIds): array
    {
        $listIds = [];

        foreach (ListItem::query()
            ->whereIn('list_id', $user->lists()->pluck('id'))
            ->whereIn('media_item_id', $itemIds)
            ->get(['list_id', 'media_item_id']) as $row) {
            // Narrowed to the positions above, so never a list's person or company.
            $listIds[(int) $row->media_item_id][] = $row->list_id;
        }

        return $listIds;
    }

    /**
     * Why the bell on a title says it is tracked: the tracking matrix's row,
     * from the same facts the calendar reads. Titles alone — the bell lives
     * on hierarchy roots.
     *
     * @param  Collection<int, MediaItem>  $items
     * @return array<int, TrackingSource|null>
     */
    private function followSources(User $user, Collection $items): array
    {
        $rootIds = [];

        foreach ($items as $item) {
            if ($item->parent_id === null) {
                $rootIds[] = $item->id;
            }
        }

        $facts = $this->tracking->forUserTitles($user, $rootIds);
        $sources = [];

        foreach ($rootIds as $id) {
            $sources[$id] = ($facts[$id] ?? null)?->source();
        }

        return $sources;
    }
}

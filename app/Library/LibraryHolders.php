<?php

namespace App\Library;

use App\Catalog\Hierarchy;
use App\Models\Favorite;
use App\Models\Follow;
use App\Models\LibraryEntry;
use App\Models\ListItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use App\Models\WatchCycle;
use App\Support\Numbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Who keeps a title in their library. One answer for every question that
 * depends on it: whether a title its source dropped may be deleted, and whom
 * to tell that it became an archive entry.
 */
final readonly class LibraryHolders
{
    public function __construct(private Hierarchy $hierarchy) {}

    /**
     * Users holding anything on these items: a play, rating, favorite, open
     * state, rewatch cycle, a bell or mute of their own, or an entry on one
     * of their own lists. A bell a followed creator rang is not theirs: a
     * title only it holds is one the user may never have seen.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    public function userIds(Collection $ids): Collection
    {
        $held = collect([
            Play::query()->whereIn('media_item_id', $ids)->distinct()->pluck('user_id'),
            Rating::query()->whereIn('media_item_id', $ids)->distinct()->pluck('user_id'),
            Favorite::query()->whereIn('media_item_id', $ids)->distinct()->pluck('user_id'),
            MediaUserState::query()->whereIn('media_item_id', $ids)->distinct()->pluck('user_id'),
            WatchCycle::query()->whereIn('media_item_id', $ids)->distinct()->pluck('user_id'),
            Follow::query()->chosenByUser()->whereIn('media_item_id', $ids)->distinct()->pluck('user_id'),
            ListItem::query()
                ->join('lists', 'lists.id', '=', 'list_items.list_id')
                ->whereIn('list_items.media_item_id', $ids)
                ->whereNotNull('lists.user_id')
                ->distinct()
                ->pluck('lists.user_id'),
        ])->flatten();

        return collect(Numbers::ints($held))->unique()->values();
    }

    /**
     * Which of these items the user holds anything on — the same holdings
     * userIds() counts, asked of one user.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    public function heldBy(int $userId, Collection $ids): Collection
    {
        $played = Numbers::ints(Play::query()->where('user_id', $userId)->whereIn('media_item_id', $ids)->distinct()->pluck('media_item_id'));

        return $this->heldBesidesHistory($userId, $ids)
            ->merge($played)
            ->unique()
            ->values();
    }

    /**
     * The roots of every title the user holds anything on. History is read
     * off the projection, one row per position keyed by its root, so a heavy
     * library is not climbed episode by episode; the other holdings are a few
     * rows, climbed to their roots.
     *
     * @return Collection<int, int>
     */
    public function rootsHeldBy(int $userId): Collection
    {
        $withHistory = Numbers::ints(LibraryEntry::query()->where('user_id', $userId)->distinct()->pluck('root_id'));

        return collect($withHistory)
            ->merge($this->hierarchy->rootIds($this->heldBesidesHistory($userId, null)))
            ->unique()
            ->values();
    }

    /**
     * Every holding but the plays: a rating, favorite, open state, rewatch
     * cycle, a bell or mute of their own, or an entry on one of the user's
     * own lists.
     *
     * @param  Collection<int, int>|null  $ids  null: on any item
     * @return Collection<int, int>
     */
    private function heldBesidesHistory(int $userId, ?Collection $ids): Collection
    {
        $on = fn (Builder|QueryBuilder $query, string $column = 'media_item_id') => $ids === null
            ? $query->whereNotNull($column)
            : $query->whereIn($column, $ids);

        $held = collect([
            $on(Rating::query()->where('user_id', $userId))->pluck('media_item_id'),
            $on(Favorite::query()->where('user_id', $userId))->pluck('media_item_id'),
            $on(MediaUserState::query()->where('user_id', $userId))->pluck('media_item_id'),
            $on(WatchCycle::query()->where('user_id', $userId))->distinct()->pluck('media_item_id'),
            $on(Follow::query()->chosenByUser()->where('user_id', $userId))->pluck('media_item_id'),
            $on(ListItem::query()->join('lists', 'lists.id', '=', 'list_items.list_id')->where('lists.user_id', $userId), 'list_items.media_item_id')
                ->distinct()
                ->pluck('list_items.media_item_id'),
        ])->flatten();

        return collect(Numbers::ints($held))->unique()->values();
    }

    /**
     * Nothing points at the tree: no user holds it and no collection lists
     * it. Such a title exists only because it was opened once.
     *
     * @param  Collection<int, int>  $ids  a tree, as Hierarchy::subtreeIds() gives it
     */
    public function isOrphan(Collection $ids): bool
    {
        return $this->userIds($ids)->isEmpty()
            && ! ListItem::query()->whereIn('media_item_id', $ids)->exists();
    }
}

<?php

namespace App\Library;

use App\Catalog\Creators\CreatorKind;
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
    /** How many items one question names: well under the database's bound on parameters. */
    private const CHUNK = 5000;

    /** How many trees are climbed together. */
    private const ROOTS_AT_ONCE = 50;

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
        return $this->heldRoots($userId);
    }

    /**
     * The roots of every title anyone holds anything on, as rootsHeldBy().
     *
     * @return Collection<int, int>
     */
    public function rootsHeldByAnyone(): Collection
    {
        return $this->heldRoots(null);
    }

    /**
     * @param  int|null  $userId  null: anyone's
     * @return Collection<int, int>
     */
    private function heldRoots(?int $userId): Collection
    {
        $withHistory = Numbers::ints(LibraryEntry::query()->when($userId !== null, fn (Builder $entries) => $entries->where('user_id', $userId))->distinct()->pluck('root_id'));

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
     * @param  int|null  $userId  null: anyone's
     * @param  Collection<int, int>|null  $ids  null: on any item
     * @return Collection<int, int>
     */
    private function heldBesidesHistory(?int $userId, ?Collection $ids): Collection
    {
        $on = fn (Builder|QueryBuilder $query, string $column = 'media_item_id') => $ids === null
            ? $query->whereNotNull($column)
            : $query->whereIn($column, $ids);
        $of = fn (Builder $query, string $column = 'user_id') => $userId === null ? $query : $query->where($column, $userId);

        $held = collect([
            $on($of(Rating::query()))->pluck('media_item_id'),
            $on($of(Favorite::query()))->pluck('media_item_id'),
            $on($of(MediaUserState::query()))->pluck('media_item_id'),
            $on($of(WatchCycle::query()))->distinct()->pluck('media_item_id'),
            $on($of(Follow::query()->chosenByUser()))->pluck('media_item_id'),
            // A collection's own list belongs to no user.
            $on($of(ListItem::query()->join('lists', 'lists.id', '=', 'list_items.list_id')->whereNotNull('lists.user_id'), 'lists.user_id'), 'list_items.media_item_id')
                ->distinct()
                ->pluck('list_items.media_item_id'),
        ])->flatten();

        return collect(Numbers::ints($held))->unique()->values();
    }

    /**
     * Of these roots, those nothing points at, asked of each whole tree in
     * one go: nobody holds anything in it, no list (a user's or a
     * collection's) lists it, and no bell is on it, not even one a followed
     * creator rang. That bell is on its way to a notification, so the title
     * is not one merely opened once.
     *
     * @param  list<int>  $rootIds
     * @return list<int>
     */
    public function unreferencedOf(array $rootIds): array
    {
        // A few roots at a time: a show's episodes are many, and each level
        // of its tree is asked by its ids.
        return array_merge(...array_map($this->unreferencedAmong(...), array_chunk($rootIds, self::ROOTS_AT_ONCE)));
    }

    /**
     * @param  list<int>  $rootIds
     * @return list<int>
     */
    private function unreferencedAmong(array $rootIds): array
    {
        $rootOf = $this->hierarchy->treesOf($rootIds);
        $pointed = [];

        foreach (array_chunk(array_keys($rootOf), self::CHUNK) as $chunk) {
            $ids = collect($chunk);

            foreach ([
                ...Numbers::ints(Play::query()->whereIn('media_item_id', $chunk)->distinct()->pluck('media_item_id')),
                ...Numbers::ints(ListItem::query()->whereIn('media_item_id', $chunk)->distinct()->pluck('media_item_id')),
                ...Numbers::ints(Follow::query()->whereIn('media_item_id', $chunk)->distinct()->pluck('media_item_id')),
                ...$this->heldBesidesHistory(null, $ids)->all(),
            ] as $item) {
                $pointed[$rootOf[$item]] = true;
            }
        }

        return array_values(array_filter($rootIds, fn (int $root): bool => ! isset($pointed[$root])));
    }

    /**
     * Narrows a query of people, companies or collections to those nothing
     * in a library points at: no bell on them, no bell they back, and, for a
     * person or company, no entry on anyone's list. Whether the catalog still
     * needs one (a credit, a collection's parts) is the catalog's question.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $creators
     * @return Builder<TModel>
     */
    public function unheldCreators(Builder $creators, CreatorKind $kind): Builder
    {
        $table = $creators->getModel()->getTable();
        $column = Follow::creatorColumn($kind);
        [$pivot, $pivotColumn] = Follow::sponsorPivot($kind);

        $creators
            ->whereNotExists(fn (QueryBuilder $bells) => $bells->selectRaw('1')->from('follows')->whereColumn("follows.{$column}", "{$table}.id"))
            ->whereNotExists(fn (QueryBuilder $backing) => $backing->selectRaw('1')->from($pivot)->whereColumn("{$pivot}.{$pivotColumn}", "{$table}.id"));

        // A list entry names a person or a company; a collection is a list itself.
        if ($kind !== CreatorKind::Collection) {
            $creators->whereNotExists(fn (QueryBuilder $listed) => $listed->selectRaw('1')->from('list_items')->whereColumn("list_items.{$column}", "{$table}.id"));
        }

        return $creators;
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

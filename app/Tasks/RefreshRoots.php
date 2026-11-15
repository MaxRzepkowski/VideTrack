<?php

namespace App\Tasks;

use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Models\LibraryEntry;
use App\Models\ListItem;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use App\Support\Numbers;
use App\Tracking\Surface;
use App\Tracking\TrackedTitles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** The persisted roots the background refresh asks about every day. */
final readonly class RefreshRoots
{
    /** Ids per query when narrowing a set down to its open titles. */
    private const CHUNK = 1000;

    public function __construct(
        private MediaTypeRegistry $types,
        private Hierarchy $hierarchy,
        private TrackedTitles $tracked,
    ) {}

    /**
     * The daily set, by id: shows anyone has watched any of, roots with
     * episodes still to come, everything on a watchlist, the archive entries,
     * and every open title somebody would be notified about — less the roots
     * of $coveredTypes, whose complete list of changes answered today. An
     * archive entry stays: a list of changes says nothing of a title gone.
     *
     * @param  list<string>  $coveredTypes
     * @return Collection<int, int>
     */
    public function activeIds(array $coveredTypes = []): Collection
    {
        $ids = $this->dailyIds();

        if ($coveredTypes === []) {
            return $ids;
        }

        $kept = [];

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            $uncovered = Numbers::ints(MediaItem::query()
                ->whereIn('id', $chunk->all())
                ->where(fn (Builder $root) => $root->whereNotIn('type', $coveredTypes)->orWhereNotNull('gone_at'))
                ->pluck('id'));

            $kept = [...$kept, ...$uncovered];
        }

        return collect($kept);
    }

    /**
     * @return Collection<int, int>
     */
    private function dailyIds(): Collection
    {
        $archived = Numbers::ints(MediaItem::query()->whereNull('parent_id')->whereNotNull('gone_at')->pluck('id'));

        return $this->watchedByAnyone()
            ->concat($this->withUpcomingReleases())
            ->concat($this->onWatchlists())
            // Archive entries are asked about daily: one the source restores
            // comes back to life, and one nobody holds any more is pruned.
            ->concat($archived)
            ->concat($this->notifiedAndOpen())
            ->unique()
            ->values();
    }

    /**
     * Titles anyone's notifications carry — by the same matrix the
     * notifications go by — that can still change in a way they would be
     * told about. A show with only a bell on it, or a book on a list of its
     * own, is announced the day its news comes out, not when the cycle
     * reaches it; a released film nobody waits for is left to the cycle.
     *
     * @return Collection<int, int>
     */
    private function notifiedAndOpen(): Collection
    {
        $notified = collect();

        foreach (User::query()->get() as $user) {
            $notified = $notified->concat(array_keys($this->tracked->of($user, Surface::Notifications)));
        }

        $open = [];

        foreach ($notified->unique()->chunk(self::CHUNK) as $chunk) {
            $ids = Numbers::ints(MediaItem::query()
                ->whereIn('id', $chunk->all())
                ->open($this->types->containerRootCodes(), today())
                ->pluck('id'));

            $open = [...$open, ...$ids];
        }

        return collect($open);
    }

    /**
     * Shows with anything logged on them by anyone, however that viewing
     * stands now: a dropped show still gets its new season announced.
     *
     * @return Collection<int, int>
     */
    private function watchedByAnyone(): Collection
    {
        $containers = $this->types->containerRootCodes();

        return collect(Numbers::ints(MediaItem::query()
            ->whereIn('type', $containers)
            ->whereIn('id', LibraryEntry::query()->select('root_id')->ofRoots()->where('played', true))
            ->pluck('id')));
    }

    /**
     * Roots of persisted items with a release today or later (upcoming
     * episodes keep a show "active" even when nobody played it yet).
     *
     * @return Collection<int, int>
     */
    private function withUpcomingReleases(): Collection
    {
        // A plain comparison, not whereDate(): the column is stored
        // midnight-stamped, and a function over it would skip the index.
        $upcomingIds = MediaItem::query()
            ->whereNotNull('parent_id')
            ->where('release_date', '>=', today()->toDateString())
            ->pluck('id');

        return $this->hierarchy->rootIds(Numbers::ints($upcomingIds));
    }

    /**
     * Roots behind every user's watchlist positions — titles somebody is
     * explicitly waiting for, which is why they join the DAILY set rather than
     * wait for the cycle. Without this a watchlisted film sits a week between
     * reads, long enough for a release date added upstream
     * mid-week to be already in the past by the time we store it, and for the
     * ping about it to never fire.
     *
     * Unlike the watched shows this keeps flat types too: a watchlisted film
     * or game has no children, and those are exactly the ones whose dates move.
     *
     * @return Collection<int, int>
     */
    private function onWatchlists(): Collection
    {
        $itemIds = ListItem::query()
            ->whereNotNull('media_item_id')
            ->whereIn('list_id', MediaList::query()->where('system_key', MediaList::SYSTEM_WATCHLIST)->select('id'))
            ->distinct()
            ->pluck('media_item_id');

        return $this->hierarchy->rootIds(Numbers::ints($itemIds));
    }
}

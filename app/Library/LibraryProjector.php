<?php

namespace App\Library;

use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Models\LibraryEntry;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use App\Models\WatchCycle;
use App\Support\Numbers;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `library_entries` in step with the history: after a write it works
 * out every position of the titles the write touched and stores what
 * changed. A title is projected whole — a play on one episode moves its
 * season, its show and the season the show's next episode now sits in. A
 * title the user has no history on has no entries; the reader works such a
 * container out live.
 *
 * Holds one piece of state: the users whose writes come in a batch (an
 * import) and the titles those writes touched, projected once at the end.
 */
final class LibraryProjector
{
    /** Titles worked out together: enough to batch the queries, few enough to keep the models in memory small. */
    private const ROOTS_PER_PASS = 50;

    /** Entries per upsert: a long show's thousand positions stay within the driver's bound parameters. */
    private const ENTRIES_PER_UPSERT = 200;

    /** How often a write that lost a race with another write of the same title runs again. */
    private const WRITE_ATTEMPTS = 3;

    /** @var array<int, array<int, true>> user id => root ids touched while the user's batch runs */
    private array $deferred = [];

    public function __construct(
        private readonly LibraryStateCalculator $calculator,
        private readonly Hierarchy $hierarchy,
        private readonly MediaTypeRegistry $types,
    ) {}

    /**
     * Runs a batch of writes for the user and projects the titles they
     * touched once, when it ends — also when it ends in an exception, since
     * what it wrote until then is history.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function deferring(User $user, Closure $work): mixed
    {
        if (isset($this->deferred[$user->id])) {
            return $work();
        }

        $this->deferred[$user->id] = [];

        try {
            return $work();
        } finally {
            $rootIds = array_keys($this->deferred[$user->id]);
            unset($this->deferred[$user->id]);

            $this->projectRoots($user, $rootIds);
        }
    }

    /**
     * @param  iterable<int>  $itemIds  any position of the titles, at any depth
     */
    public function projectItems(User $user, iterable $itemIds): void
    {
        $this->projectRoots($user, $this->hierarchy->rootIds($itemIds)->all());
    }

    /**
     * @param  iterable<int>  $rootIds
     */
    public function projectRoots(User $user, iterable $rootIds): void
    {
        $rootIds = array_values(array_unique([...$rootIds]));

        if (isset($this->deferred[$user->id])) {
            $this->deferred[$user->id] += array_fill_keys($rootIds, true);

            return;
        }

        foreach (array_chunk($rootIds, self::ROOTS_PER_PASS) as $chunk) {
            $this->write($user, $chunk);
        }
    }

    /**
     * Every title the user has history on, from nothing, and every title
     * the projection holds: one whose history is gone loses its entries the
     * way a write takes them, title by title, so a title another process
     * projects meanwhile keeps its own.
     */
    public function rebuild(User $user): void
    {
        $rootIds = $this->hierarchy->rootIds(array_unique(Numbers::ints(
            Play::query()->where('user_id', $user->id)->distinct()->pluck('media_item_id')
                ->concat(MediaUserState::query()->where('user_id', $user->id)->pluck('media_item_id'))
                ->concat(WatchCycle::query()->where('user_id', $user->id)->pluck('media_item_id')),
        )));

        $this->projectRoots($user, $rootIds->concat(
            Numbers::ints(LibraryEntry::query()->where('user_id', $user->id)->distinct()->pluck('root_id')),
        ));
    }

    /**
     * An episode out since `$since` joins its show's count of aired episodes
     * and may become the one up next, a change no write announces: the titles
     * with such a release are worked out again for everyone with history on
     * them.
     *
     * @return array<int, list<int>> user id => the titles worked out for them
     */
    public function projectReleasedSince(CarbonInterface $since): array
    {
        $today = today();
        $released = MediaItem::query()
            ->whereNotNull('parent_id')
            ->where('release_date', '>=', $since->copy()->startOfDay())
            ->where('release_date', '<', MediaItem::airedCutoff($today))
            ->pluck('id');

        $rootsByUser = [];
        $entries = LibraryEntry::query()
            ->ofRoots()
            ->whereIn('root_id', $this->hierarchy->rootIds(Numbers::ints($released)))
            ->get(['user_id', 'root_id']);

        foreach ($entries as $entry) {
            $rootsByUser[$entry->user_id][] = $entry->root_id;
        }

        foreach (User::query()->whereIn('id', array_keys($rootsByUser))->get() as $user) {
            $this->projectRoots($user, $rootsByUser[$user->id]);
        }

        return $rootsByUser;
    }

    /** The user's history is being wiped: its projection goes in the same transaction. */
    public function forget(User $user): void
    {
        LibraryEntry::query()->where('user_id', $user->id)->delete();
    }

    /**
     * @param  list<int>  $rootIds
     */
    private function write(User $user, array $rootIds): void
    {
        // Read and written in one transaction: two writes of one title at
        // once (a scrobble and a click) would otherwise let the one that read
        // the older history write last. SQLite answers the loser with "database
        // is locked", and the transaction runs again on the newer history.
        DB::transaction(function () use ($user, $rootIds): void {
            $wanted = $this->entries($user, $this->hierarchy->treesOf($rootIds));

            $held = LibraryEntry::query()
                ->where('user_id', $user->id)
                ->whereIn('root_id', $rootIds)
                ->get()
                ->keyBy('media_item_id');

            $stale = $held->keys()->diff(array_keys($wanted));

            if ($stale->isNotEmpty()) {
                LibraryEntry::query()
                    ->where('user_id', $user->id)
                    ->whereIn('media_item_id', $stale)
                    ->delete();
            }

            $changed = [];

            foreach ($wanted as $itemId => $columns) {
                $entry = $held->get($itemId);

                if ($entry === null || ! $this->holds($entry, $columns)) {
                    $changed[] = ['user_id' => $user->id, 'media_item_id' => $itemId, ...$columns];
                }
            }

            foreach (array_chunk($changed, self::ENTRIES_PER_UPSERT) as $rows) {
                LibraryEntry::query()->upsert($rows, ['user_id', 'media_item_id']);
            }
        }, self::WRITE_ATTEMPTS);
    }

    /**
     * The entries the titles should have: every position of a title with
     * history, none of one without.
     *
     * @param  array<int, int>  $rootOf  item id => root id, every position of the titles
     * @return array<int, array<string, scalar|null>> item id => its columns
     */
    private function entries(User $user, array $rootOf): array
    {
        $itemIds = array_keys($rootOf);
        $logged = $this->logged($user, $itemIds);

        $withHistory = array_flip(array_unique([
            ...array_map(fn (int $itemId): int => $rootOf[$itemId], array_keys($logged)),
            ...array_map(
                fn (int $itemId): int => $rootOf[$itemId],
                Numbers::ints(MediaUserState::query()->where('user_id', $user->id)->whereIn('media_item_id', $itemIds)->pluck('media_item_id')),
            ),
            ...array_map(
                fn (int $itemId): int => $rootOf[$itemId],
                Numbers::ints(WatchCycle::query()->where('user_id', $user->id)->whereIn('media_item_id', $itemIds)->pluck('media_item_id')),
            ),
        ]));

        // A type whose module the instance switched off has no definition to
        // work it out by; its titles wait for the module to return (the
        // container's boot rebuilds every library).
        // The tree's shape and what the counters weigh, nothing more: a long
        // show's episodes carry their synopses in `metadata`.
        $items = MediaItem::query()
            ->whereIn('id', array_keys(array_filter($rootOf, fn (int $rootId): bool => isset($withHistory[$rootId]))))
            ->get(['id', 'parent_id', 'type', 'provider', 'provider_ref', 'title', 'number', 'runtime', 'release_date'])
            ->filter(fn (MediaItem $item): bool => $this->types->has($item->type))
            ->keyBy(fn (MediaItem $item): int => $item->id);

        if ($items->isEmpty()) {
            return [];
        }

        $states = $this->calculator->compute($user, $items);
        $activity = $this->activity($items, $logged);
        $entries = [];

        foreach ($items as $itemId => $item) {
            $entries[$itemId] = [
                'root_id' => $rootOf[$itemId],
                ...$states[$itemId]->toColumns(),
                ...$activity[$itemId],
            ];
        }

        return $entries;
    }

    /**
     * Per item with any entry of its own: when its latest one was logged.
     *
     * @param  list<int>  $itemIds
     * @return array<int, string> item id => the latest entry's moment
     */
    private function logged(User $user, array $itemIds): array
    {
        $latest = Play::query()
            ->where('user_id', $user->id)
            ->whereIn('media_item_id', $itemIds)
            ->groupBy('media_item_id')
            ->selectRaw('media_item_id, max(watched_at) as last_at')
            ->toBase()
            ->pluck('last_at', 'media_item_id');
        $logged = [];

        foreach ($latest as $itemId => $at) {
            if (is_string($at)) {
                $logged[(int) $itemId] = $at;
            }
        }

        return $logged;
    }

    /**
     * What the shelves read besides the state, rolled up the tree: whether
     * anything was logged at or below a position (a drop on the show counts),
     * and when a playable under it last was — any outcome, any cycle,
     * specials included.
     *
     * @param  Collection<int, MediaItem>  $items
     * @param  array<int, string>  $logged
     * @return array<int, array{played: bool, last_played_at: string|null}>
     */
    private function activity(Collection $items, array $logged): array
    {
        $parentOf = [];
        $playable = [];
        $activity = [];

        foreach ($items as $itemId => $item) {
            $parentOf[$itemId] = $item->parent_id;
            $playable[$itemId] = $this->types->get($item->type)->playable;
            $activity[$itemId] = ['played' => false, 'last_played_at' => null];
        }

        foreach ($logged as $itemId => $at) {
            $countsTime = $playable[$itemId] ?? false;
            $nodeId = isset($activity[$itemId]) ? $itemId : null;

            while ($nodeId !== null) {
                $last = $activity[$nodeId]['last_played_at'];
                $activity[$nodeId] = [
                    'played' => true,
                    'last_played_at' => $countsTime && ($last === null || $at > $last) ? $at : $last,
                ];
                $parentId = $parentOf[$nodeId];
                $nodeId = $parentId !== null && isset($activity[$parentId]) ? $parentId : null;
            }
        }

        return $activity;
    }

    /**
     * Whether the stored entry already holds these columns. Compared as the
     * database returns them — no casts: a long show's thousand entries are
     * read back on every play of one of its episodes. A flag comes back as
     * 0 or 1, everything else as it was written.
     *
     * @param  array<string, scalar|null>  $columns
     */
    private function holds(LibraryEntry $entry, array $columns): bool
    {
        $raw = $entry->getAttributes();

        foreach ($columns as $column => $value) {
            if ((is_bool($value) ? (int) $value : $value) !== $raw[$column]) {
                return false;
            }
        }

        return true;
    }
}

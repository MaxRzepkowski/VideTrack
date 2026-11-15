<?php

namespace App\Library;

use App\Catalog\Data\CascadeLeaf;
use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Enums\PlayOutcome;
use App\Library\Data\HistoryFacts;
use App\Library\Data\PlayTally;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use App\Models\WatchCycle;
use App\Support\Numbers;
use App\Support\QueryRow;
use Illuminate\Support\Collection;

/**
 * Reads, in a fixed number of queries, the history a set of persisted
 * positions' states are worked out from.
 */
final readonly class HistoryFactsQuery
{
    public function __construct(
        private MediaTypeRegistry $types,
        private WatchCycleResolver $cycles,
        private Hierarchy $hierarchy,
    ) {}

    /**
     * @param  array<int, MediaItem>  $items  by id
     */
    public function of(User $user, array $items): HistoryFacts
    {
        $isPlayable = fn (MediaItem $item): bool => $this->types->get($item->type)->playable;
        $isRoot = fn (MediaItem $item): bool => $this->types->get($item->type)->isRoot();
        $playable = collect($items)->filter($isPlayable);
        $containers = collect($items)->reject($isPlayable);

        $leaves = $this->hierarchy->cascadeLeaves($containers->values());

        // Root context for nested containers (seasons): the season holding
        // the show's "first gap" is in progress even with zero own plays.
        $nestedRootOf = $this->hierarchy->rootIdsByItem(Numbers::ints($containers->reject($isRoot)->pluck('id')));

        $missingRoots = MediaItem::query()
            ->whereIn('id', collect($nestedRootOf)->unique()->reject(fn (int $id) => isset($leaves[$id])))
            ->get();

        $leaves += $this->hierarchy->cascadeLeaves($missingRoots);

        // Roots by id, whether they were asked for or only reached through a
        // season: a season declares no runtime of its own, so the length its
        // episodes fall back to lives up here.
        $roots = $containers->filter($isRoot)->concat($missingRoots)->keyBy(fn (MediaItem $root): int => $root->id);

        $rootOf = $this->rootOf($playable, $containers, $nestedRootOf, $missingRoots, $leaves);
        $activeCycles = $this->cycles->activeForRoots($user, $this->cycleRoots($roots, $playable, $rootOf));

        // A root owns the active cycle; seasons and episodes inherit it.
        $cycleIdOf = [];

        foreach ($rootOf as $itemId => $rootId) {
            $cycleIdOf[$itemId] = ($activeCycles[$rootId] ?? null)?->id;
        }

        /** @var Collection<int, CascadeLeaf> $allLeaves */
        $allLeaves = collect($leaves)->flatten(1);
        [$history, $current, $byCycle] = $this->plays(
            $user,
            [...$playable->map(fn (MediaItem $item): int => $item->id)->values(), ...$allLeaves->map(fn (CascadeLeaf $leaf): int => $leaf->id)],
            $cycleIdOf,
        );

        // Drops on container nodes: a skipped season settles its episodes, a
        // dropped show or season shows the drop as its status.
        [$drops, $dropsByCycle] = $this->drops(
            $user,
            $containers->values()->map(fn (MediaItem $item) => $item->id)
                ->merge($allLeaves->map(fn (CascadeLeaf $leaf) => $leaf->parentId))
                ->merge(collect($nestedRootOf)->values())
                ->filter()
                ->unique()
                ->values()
                ->all(),
            $cycleIdOf,
        );

        $itemIds = collect($items)->map(fn (MediaItem $item) => $item->id)->values();

        return new HistoryFacts(
            items: $items,
            leaves: $leaves,
            rootOf: $rootOf,
            roots: $roots->all(),
            cycles: array_map(fn (WatchCycle $cycle): int => $cycle->number, array_filter($activeCycles)),
            history: $history,
            current: $current,
            byCycle: $byCycle,
            drops: $drops,
            dropsByCycle: $dropsByCycle,
            // Roots ride along even when nobody asked for them: a season met
            // on a list has no show next to it, and the show's open state is
            // what decides whether that season still reads as in progress.
            openStates: MediaUserState::query()
                ->where('user_id', $user->id)
                ->whereIn('media_item_id', $itemIds->concat(array_values($nestedRootOf))->unique())
                ->get(['media_item_id', 'status', 'progress'])
                ->keyBy(fn (MediaUserState $state): int => $state->media_item_id)
                ->all(),
        );
    }

    /**
     * Every position asked for, every leaf and every leaf's parent, mapped to
     * the root of its hierarchy.
     *
     * @param  Collection<int, MediaItem>  $playable
     * @param  Collection<int, MediaItem>  $containers
     * @param  array<int, int>  $nestedRootOf
     * @param  Collection<int, MediaItem>  $missingRoots
     * @param  array<int, list<CascadeLeaf>>  $leaves
     * @return array<int, int>
     */
    private function rootOf(Collection $playable, Collection $containers, array $nestedRootOf, Collection $missingRoots, array $leaves): array
    {
        $rootOf = $this->hierarchy->rootIdsByItem(Numbers::ints($playable->pluck('id')));

        foreach ($containers as $container) {
            $rootOf[$container->id] = $this->types->get($container->type)->isRoot()
                ? $container->id
                : $nestedRootOf[$container->id];
        }

        foreach ($missingRoots as $root) {
            $rootOf[$root->id] = $root->id;
        }

        foreach ($leaves as $containerId => $containerLeaves) {
            $rootId = $rootOf[$containerId] ?? $containerId;

            foreach ($containerLeaves as $leaf) {
                $rootOf[$leaf->id] = $rootId;

                if ($leaf->parentId !== null) {
                    $rootOf[$leaf->parentId] = $rootId;
                }
            }
        }

        return $rootOf;
    }

    /**
     * The roots whose active cycle decides the positions' counters: the root
     * containers, the playables that are their own root (a film), and the
     * roots of the rest.
     *
     * @param  Collection<int, MediaItem>  $roots
     * @param  Collection<int, MediaItem>  $playable
     * @param  array<int, int>  $rootOf
     * @return Collection<int, MediaItem>
     */
    private function cycleRoots(Collection $roots, Collection $playable, array $rootOf): Collection
    {
        $cycleRoots = $roots->keyBy('id');

        foreach ($playable as $item) {
            if (($rootOf[$item->id] ?? null) === $item->id) {
                $cycleRoots->put($item->id, $item);
            }
        }

        $missing = collect($rootOf)
            ->values()
            ->unique()
            ->reject(fn (int $rootId): bool => $cycleRoots->has($rootId));

        if ($missing->isNotEmpty()) {
            foreach (MediaItem::query()->whereIn('id', $missing)->get() as $root) {
                $cycleRoots->put($root->id, $root);
            }
        }

        return $cycleRoots->values();
    }

    /**
     * One aggregate feeds two views: the totals across the immutable history
     * and the current cycle's slice that progress, gaps and status read.
     *
     * @param  list<int>  $itemIds
     * @param  array<int, int|null>  $cycleIdOf
     * @return array{array<int, PlayTally>, array<int, PlayTally>, array<string, array<int, PlayTally>>}
     */
    private function plays(User $user, array $itemIds, array $cycleIdOf): array
    {
        $rows = Play::query()
            ->where('user_id', $user->id)
            ->whereIn('media_item_id', $itemIds)
            ->selectRaw(implode(', ', [
                'media_item_id',
                'watch_cycle_id',
                'count(*) as entry_count',
                "sum(case when outcome = 'completed' then 1 else 0 end) as play_count",
                "max(case when outcome = 'completed' then watched_at end) as last_watched_at",
                "max(case when outcome = 'dropped' then watched_at end) as last_dropped_at",
                // Sortable (watched_at, id) key per outcome so "which entry is
                // latest" tiebreaks on creation order, not a bare timestamp —
                // a completion sharing an explicit date with a drop must win.
                "max(case when outcome = 'completed' then watched_at || printf('%020d', id) end) as last_watched_key",
                "max(case when outcome = 'dropped' then watched_at || printf('%020d', id) end) as last_dropped_key",
                "max(case when outcome = 'dropped' then amount end) as dropped_amount",
                'sum(amount) as total_amount',
            ]))
            ->groupBy('media_item_id', 'watch_cycle_id')
            ->toBase()
            ->get();

        $byItem = [];
        $current = [];
        $byCycle = [];

        foreach ($rows as $row) {
            $itemId = QueryRow::int($row, 'media_item_id');
            $cycleId = QueryRow::intOrNull($row, 'watch_cycle_id');
            $tally = PlayTally::fromRow($row);

            $byItem[$itemId][] = $tally;
            $byCycle[WatchCycleResolver::key($cycleId)][$itemId] = $tally;

            if ($cycleId === ($cycleIdOf[$itemId] ?? null)) {
                $current[$itemId] = $tally;
            }
        }

        return [array_map(PlayTally::merge(...), $byItem), $current, $byCycle];
    }

    /**
     * When each container was dropped, in the cycle it is in now and in
     * every cycle by key.
     *
     * @param  array<int, int>  $containerIds
     * @param  array<int, int|null>  $cycleIdOf
     * @return array{array<int, string>, array<string, array<int, true>>}
     */
    private function drops(User $user, array $containerIds, array $cycleIdOf): array
    {
        $rows = Play::query()
            ->where('user_id', $user->id)
            ->where('outcome', PlayOutcome::Dropped)
            ->whereIn('media_item_id', $containerIds)
            ->groupBy('media_item_id', 'watch_cycle_id')
            ->selectRaw('media_item_id, watch_cycle_id, max(watched_at) as dropped_at')
            ->toBase()
            ->get();

        $drops = [];
        $byCycle = [];

        foreach ($rows as $row) {
            $itemId = QueryRow::int($row, 'media_item_id');
            $cycleId = QueryRow::intOrNull($row, 'watch_cycle_id');
            $byCycle[WatchCycleResolver::key($cycleId)][$itemId] = true;

            if (($cycleIdOf[$itemId] ?? null) === $cycleId) {
                $drops[$itemId] = QueryRow::text($row, 'dropped_at');
            }
        }

        return [$drops, $byCycle];
    }
}

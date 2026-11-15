<?php

namespace App\Library;

use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Enums\PlayOutcome;
use App\Library\Data\WatchCycleSummary;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use App\Models\WatchCycle;
use App\Support\QueryRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Builds the cycle-history rows shown by a container's watched modal.
 * Cycle one is implicit and therefore synthesized even when it has no plays.
 */
final readonly class WatchCycleSummaryResolver
{
    public function __construct(
        private MediaTypeRegistry $types,
        private Hierarchy $hierarchy,
    ) {}

    /**
     * @return list<WatchCycleSummary> the newest run first, the implicit first one last
     */
    public function resolve(User $user, MediaItem $root): array
    {
        $cycles = WatchCycle::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $root->id)
            // By number, like every other reader of cycles: an import can create
            // run 3 before run 2, and the active one is the highest.
            ->orderBy('number')
            ->get();

        $activeCycleId = $cycles->last()?->id;
        [$subtreeIds, $airedParents] = $this->progression($root);

        $stats = Play::query()
            ->where('user_id', $user->id)
            ->whereIn('media_item_id', $subtreeIds)
            ->groupBy('media_item_id', 'watch_cycle_id')
            ->selectRaw(implode(', ', [
                'media_item_id',
                'watch_cycle_id',
                'count(*) as entry_count',
                sprintf("sum(case when outcome = '%s' then 1 else 0 end) as completed_count", PlayOutcome::Completed->value),
                sprintf("sum(case when outcome = '%s' then 1 else 0 end) as dropped_count", PlayOutcome::Dropped->value),
                'min(watched_at) as first_play_at',
                'max(watched_at) as last_play_at',
            ]))
            ->toBase()
            ->get()
            ->groupBy(fn (stdClass $row): string => WatchCycleResolver::key(QueryRow::intOrNull($row, 'watch_cycle_id')));

        $rows = array_values($cycles
            ->map(fn (WatchCycle $cycle): WatchCycleSummary => $this->summary(
                number: $cycle->number,
                startedAt: $cycle->started_at->toIso8601String(),
                airedParents: $airedParents,
                stats: $stats[WatchCycleResolver::key($cycle->id)] ?? new Collection,
                active: $cycle->id === $activeCycleId,
            ))
            ->reverse()
            ->all());

        $rows[] = $this->summary(
            number: 1,
            startedAt: null,
            airedParents: $airedParents,
            stats: $stats[WatchCycleResolver::key(null)] ?? new Collection,
            active: $activeCycleId === null,
        );

        return $rows;
    }

    /**
     * @param  array<int, int|null>  $airedParents
     * @param  Collection<int, stdClass>  $stats
     */
    private function summary(
        int $number,
        ?string $startedAt,
        array $airedParents,
        Collection $stats,
        bool $active,
    ): WatchCycleSummary {
        $completions = [];
        $drops = [];
        $plays = 0;
        $firstPlayAt = null;
        $lastPlayAt = null;

        foreach ($stats as $row) {
            $itemId = QueryRow::int($row, 'media_item_id');
            $entries = QueryRow::int($row, 'entry_count');
            $plays += $entries;
            // min() and max() over watched_at, NOT NULL, in a non-empty group.
            $first = QueryRow::text($row, 'first_play_at');
            $last = QueryRow::text($row, 'last_play_at');
            $firstPlayAt = $firstPlayAt === null ? $first : min($firstPlayAt, $first);
            $lastPlayAt = $lastPlayAt === null ? $last : max($lastPlayAt, $last);

            if ($entries > 0) {
                $completions[$itemId] = QueryRow::int($row, 'completed_count');
            }

            if (QueryRow::int($row, 'dropped_count') > 0) {
                $drops[$itemId] = true;
            }
        }

        $run = new Settlement($completions, $drops);
        $watchedEpisodes = count(array_filter(array_keys($airedParents), fn (int $id): bool => $run->watched($id)));

        $airedEpisodes = count($airedParents);

        return new WatchCycleSummary(
            number: $number,
            startedAt: $startedAt,
            watchedEpisodes: $watchedEpisodes,
            airedEpisodes: $airedEpisodes,
            plays: $plays,
            completed: $run->completes($airedParents),
            firstPlayAt: $this->iso($firstPlayAt),
            lastPlayAt: $this->iso($lastPlayAt),
            isActive: $active,
        );
    }

    /**
     * Traverse with lightweight rows: all ids scope the history timestamps,
     * while aired parents contain only progression leaves (special branches
     * are deliberately excluded).
     *
     * @return array{list<int>, array<int, int|null>}
     */
    private function progression(MediaItem $root): array
    {
        $ids = [];
        $airedParents = [];

        foreach ($this->hierarchy->subtree($root) as $node) {
            $ids[] = $node->id;

            if ($node->inCascade && $node->aired && $this->types->get($node->type)->playable) {
                $airedParents[$node->id] = $node->parentId;
            }
        }

        return [$ids, $airedParents];
    }

    private function iso(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }
}

<?php

namespace App\Library;

use App\Catalog\Data\CascadeLeaf;
use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Enums\PositionStatus;
use App\Library\Data\EpisodePointer;
use App\Library\Data\HistoryFacts;
use App\Library\Data\HistoryState;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\StoredTime;
use Carbon\CarbonImmutable;

/**
 * Works out what a user's history makes of a set of persisted positions,
 * from what {@see HistoryFactsQuery} reads. A container's status (season or
 * show watched) is derived from its leaves, so a play moves every level
 * above it.
 */
final readonly class LibraryStateCalculator
{
    public function __construct(
        private MediaTypeRegistry $types,
        private HistoryFactsQuery $facts,
        private Hierarchy $hierarchy,
    ) {}

    /**
     * @param  iterable<MediaItem>  $items
     * @return array<int, HistoryState> by item id
     */
    public function compute(User $user, iterable $items): array
    {
        $byId = [];

        foreach ($items as $item) {
            $byId[$item->id] = $item;
        }

        $facts = $this->facts->of($user, $byId);
        $run = $facts->settlement();

        // Per container: its first gap. Per root besides: whether anything
        // was played at all (a fresh show must not mark its seasons in progress).
        $gaps = [];
        $started = [];

        foreach ($facts->leaves as $containerId => $leaves) {
            $gaps[$containerId] = $this->firstGap($leaves, $run);
            $started[$containerId] = collect($leaves)->contains(fn (CascadeLeaf $leaf): bool => isset($facts->current[$leaf->id]));
        }

        $states = [];

        foreach ($byId as $id => $item) {
            $states[$id] = $this->types->get($item->type)->playable
                ? $this->playableState($item, $facts)
                : $this->containerState($item, $facts, $run, $gaps, $started);
        }

        return $states;
    }

    /**
     * A film, a game, an episode: its own plays decide it.
     */
    private function playableState(MediaItem $item, HistoryFacts $facts): HistoryState
    {
        $history = $facts->history[$item->id] ?? null;
        $current = $facts->current[$item->id] ?? null;

        // A latest-entry drop is exposed even when an open state masks the
        // displayed status (dropped → session → complete must still offer
        // reopening vs a separate attempt); its frozen hours ride along so
        // the modal can show the merge.
        $latestDropped = $current?->latestIsDrop() ?? false;
        $plays = $history->completions ?? 0;

        return new HistoryState(
            watched: ($current->completions ?? 0) > 0,
            watchedAt: $this->moment($history?->lastWatchedAt),
            droppedAt: $this->moment($current?->lastDroppedAt),
            plays: $plays,
            cycle: $facts->cycleOf($item),
            completedCycles: $plays,
            // The displayed status prefers the open state; with none, the
            // drop IS the current relationship until a play reopens the attempt.
            status: $this->openStatus($item->id, $facts) ?? ($latestDropped ? PositionStatus::Dropped : null),
            progress: $facts->openStates[$item->id]->progress ?? null,
            // Real recorded cost across completions (games: minutes) — keeps
            // the total playtime visible after completing.
            playedAmount: $history?->totalAmount,
            droppedAmount: $latestDropped ? $current->droppedAmount : null,
            latestDropped: $latestDropped,
        );
    }

    /**
     * A show or a season: its leaves decide it, read in the cycle its root is
     * in now.
     *
     * @param  array<int, array{leaf: CascadeLeaf, position: int}|null>  $gaps
     * @param  array<int, bool>  $started
     */
    private function containerState(MediaItem $item, HistoryFacts $facts, Settlement $run, array $gaps, array $started): HistoryState
    {
        $leaves = $facts->leaves[$item->id] ?? [];
        $aired = array_values(array_filter($leaves, fn (CascadeLeaf $leaf): bool => $leaf->aired));
        $completed = fn (CascadeLeaf $leaf): bool => $run->watched($leaf->id);

        // Settled = any entry of its own, or inside a skipped season.
        // Settling moves the gap on and can finish a show, but it is not
        // watching: the "X of Y" counter reports episodes really watched, so
        // a show abandoned at 25 keeps reading 25 of 79 however its remaining
        // seasons were settled. The denominator stays the aired total.
        $settled = fn (CascadeLeaf $leaf): bool => $run->settles($leaf->id, $leaf->parentId);
        $unsettled = array_values(array_filter($aired, fn (CascadeLeaf $leaf): bool => ! $settled($leaf)));
        $watchedLeaves = array_values(array_filter($aired, $completed));

        $watchedEpisodes = count($watchedLeaves);
        $watched = $run->completes(self::parents($aired));
        $rootId = $facts->rootIdOf($item);

        // What the progress bar's hint reads, in the scope of THIS container:
        // a season page counts its own episodes, a show page all of them.
        // Settled without a completion is a skip — the grey holes in the bar —
        // and the two runtimes price the episodes on either side of where the
        // user stands. An episode the provider left without a runtime costs
        // what the show declares an episode costs, exactly as the dashboard
        // tiles price it.
        $default = $item->runtime ?? $facts->roots[$rootId]->runtime ?? 0;

        $status = $this->openStatus($item->id, $facts);

        // A dropped container (show / skipped season) reads as the displayed
        // status until a play resumes the attempt, and says when.
        if ($status === null && isset($facts->drops[$item->id])) {
            $status = PositionStatus::Dropped;
        }

        $droppedAt = $status === PositionStatus::Dropped ? $this->moment($facts->drops[$item->id] ?? null) : null;

        // In progress: own plays, or the root's first gap sits inside this
        // container (season 1 done → season 2 is 0/N in progress).
        $rootGap = ($started[$rootId] ?? false) ? ($gaps[$rootId] ?? null) : null;
        $containsGap = $rootGap !== null && collect($leaves)->contains(fn (CascadeLeaf $leaf): bool => $leaf->id === $rootGap['leaf']->id);
        $inProgress = ! $watched && ($watchedEpisodes > 0 || $containsGap);
        $gap = $gaps[$item->id] ?? null;

        // Specials (season 0) stand outside the show's progression — so they
        // get none of their own either: no counters, no progress bar, never
        // "in progress". Watching them is a deliberate side trip, not a run to
        // finish. Completing all of them still reads as watched, and the
        // pointer survives so the explicit "watch the next one" action knows
        // where it stands.
        $progression = ! $this->hierarchy->isSpecials($item);
        $inProgress = $inProgress && $progression;

        // A season reads what its show reads: dropping or pausing the show
        // closed the run, so the season the user stood in is not open any
        // more — it would otherwise keep claiming "in progress" on every
        // shelf. Only what READ as in progress changes hands: a completed
        // season keeps its completion, an untouched one stays untouched. A
        // show carries no `in_progress` of its own (that one is derived,
        // never stored), so the only open state there is to inherit is the
        // pause.
        $rootStatus = null;
        $statusInherited = false;

        if ($rootId !== $item->id) {
            // Exposed whatever the season itself reads: the client words the
            // status with it ("dropped with the show") and warns that clearing
            // it reaches the show — which is what clearing really does, see
            // ResumeAttempt.
            $rootStatus = isset($facts->drops[$rootId]) ? PositionStatus::Dropped : $this->openStatus($rootId, $facts);

            if ($rootStatus !== null && $inProgress) {
                $inProgress = false;

                // Wearing the show's status takes having started this season:
                // one that only holds the pointer with nothing watched ("0 of
                // 10") was never begun, so it reads as untouched rather than as
                // given up on. Only a season showing NOTHING of its own wears
                // it; the season's own drop or pause outranks the show's.
                if ($watchedEpisodes > 0) {
                    $statusInherited = $status === null;
                    $status ??= $rootStatus;

                    // The show's drop date only travels with the show's drop:
                    // a season paused on its own keeps no date.
                    if ($statusInherited && $rootStatus === PositionStatus::Dropped) {
                        $droppedAt ??= $this->moment($facts->drops[$rootId] ?? null);
                    }
                }
            }
        }

        return new HistoryState(
            watched: $watched,
            watchedAt: $this->moment($this->latest(array_map(fn (CascadeLeaf $leaf): ?string => $facts->current[$leaf->id]->lastWatchedAt ?? null, $leaves))),
            droppedAt: $droppedAt,
            plays: array_sum(array_map(fn (CascadeLeaf $leaf): int => $facts->history[$leaf->id]->completions ?? 0, $leaves)),
            cycle: $facts->cycleOf($item),
            completedCycles: $this->completedCycles($aired, $facts),
            status: $status,
            rootStatus: $rootStatus,
            statusInherited: $statusInherited,
            progress: $facts->openStates[$item->id]->progress ?? null,
            watchedEpisodes: $progression ? $watchedEpisodes : null,
            airedEpisodes: $progression ? count($aired) : null,
            remainingEpisodes: $progression ? count($unsettled) : null,
            skippedEpisodes: $progression ? count($aired) - count($unsettled) - $watchedEpisodes : null,
            remainingMinutes: $progression ? $this->runtimeSum($unsettled, $default) : null,
            watchedMinutes: $progression ? $this->runtimeSum($watchedLeaves, $default) : null,
            // The gap carries the same two numbers an Up Next card does —
            // which aired episode is next, and how many are still unsettled
            // behind it — so every shelf words a show's progress alike.
            position: $gap['position'] ?? null,
            nextEpisodeId: $gap === null ? null : $gap['leaf']->id,
            nextEpisode: $gap === null ? null : EpisodePointer::to($gap['leaf']),
            inProgress: $inProgress,
            progression: $progression,
            segments: $progression ? $this->segments($aired, $completed, $settled) : null,
        );
    }

    private function openStatus(int $itemId, HistoryFacts $facts): ?PositionStatus
    {
        $open = ($facts->openStates[$itemId] ?? null)?->status;

        return $open === null ? null : PositionStatus::ofOpen($open);
    }

    /**
     * The first unsettled aired leaf in hierarchy order — the shared "first
     * gap" behind nextEpisode and the in-progress pointer — and which aired
     * leaf of the container it is, counted across seasons from 1: the
     * numerator of "episode 100 of 143", which S06E02 alone cannot say.
     * Any entry settles a leaf (a skipped episode is deliberately settled),
     * and a skipped season settles all of its episodes.
     *
     * @param  list<CascadeLeaf>  $leaves  in hierarchy order
     * @return array{leaf: CascadeLeaf, position: int}|null
     */
    private function firstGap(array $leaves, Settlement $run): ?array
    {
        $gap = null;
        $position = 0;

        foreach ($leaves as $leaf) {
            if (! $leaf->aired) {
                continue;
            }

            if ($gap === null) {
                $position++;

                if (! $run->settles($leaf->id, $leaf->parentId)) {
                    $gap = $leaf;
                }

                continue;
            }

            // Leaves numbered alike (a provider leaving episode numbers out)
            // stand level with the gap and count up to it.
            if (CascadeLeaf::compare($leaf, $gap) !== 0) {
                break;
            }

            $position++;
        }

        return $gap === null ? null : ['leaf' => $gap, 'position' => $position];
    }

    /**
     * Number of cycles that complete the container as it stands today: every
     * currently aired leaf settled, at least one really watched.
     *
     * @param  list<CascadeLeaf>  $aired
     */
    private function completedCycles(array $aired, HistoryFacts $facts): int
    {
        $parents = self::parents($aired);
        $completed = 0;

        foreach (array_unique([...array_keys($facts->byCycle), ...array_keys($facts->dropsByCycle)]) as $cycle) {
            $completed += $facts->settlementIn((string) $cycle)->completes($parents) ? 1 : 0;
        }

        return $completed;
    }

    /**
     * @param  list<CascadeLeaf>  $leaves
     * @return array<int, int|null> leaf id => its parent's id
     */
    private static function parents(array $leaves): array
    {
        $parents = [];

        foreach ($leaves as $leaf) {
            $parents[$leaf->id] = $leaf->parentId;
        }

        return $parents;
    }

    /**
     * Total runtime of the given episodes; the ones the provider left without
     * one cost `$default` — the length the show declares for an episode.
     *
     * @param  list<CascadeLeaf>  $leaves
     */
    private function runtimeSum(array $leaves, int $default): int
    {
        return array_sum(array_map(fn (CascadeLeaf $leaf): int => ($leaf->runtime ?? 0) > 0 ? (int) $leaf->runtime : $default, $leaves));
    }

    /**
     * @param  list<string|null>  $moments
     */
    private function latest(array $moments): ?string
    {
        $moments = array_filter($moments);

        return $moments === [] ? null : max($moments);
    }

    /**
     * The aired leaves as the progress bar draws them, run-length encoded so
     * a 1213-episode show costs a handful of runs instead of 1213 entries.
     *
     * @param  list<CascadeLeaf>  $aired
     * @param  callable(CascadeLeaf): bool  $completed
     * @param  callable(CascadeLeaf): bool  $settled
     * @return list<array{state: EpisodeState, episodes: int}>
     */
    private function segments(array $aired, callable $completed, callable $settled): array
    {
        $segments = [];

        foreach ($aired as $leaf) {
            // A skipped episode settles the gap without ever
            // claiming to have been seen — the bar draws it as a hole.
            $state = match (true) {
                $completed($leaf) => EpisodeState::Watched,
                $settled($leaf) => EpisodeState::Skipped,
                default => EpisodeState::Pending,
            };

            $last = array_key_last($segments);

            if ($last !== null && $segments[$last]['state'] === $state) {
                $segments[$last]['episodes']++;
            } else {
                $segments[] = ['state' => $state, 'episodes' => 1];
            }
        }

        return $segments;
    }

    /**
     * A stored moment as an instant. Plays are stored in the instance
     * timezone (APP_TIMEZONE, UTC by default); the client shows them in the
     * viewer's.
     */
    private function moment(?string $moment): ?CarbonImmutable
    {
        return $moment === null ? null : StoredTime::read($moment);
    }
}

<?php

namespace App\Actions\Library;

use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Enums\PlayOutcome;
use App\Library\AttemptPolicy;
use App\Library\Attempts;
use App\Library\Events\PlayRecorded;
use App\Library\MutationContext;
use App\Library\WatchCycleResolver;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The downward mark cascade, attempt-model flavored. A
 * playable position with a completed latest attempt in the active cycle gains
 * one more play (marking again IS the rewatch); a latest DROPPED attempt in
 * that cycle is reopened and completed in place instead — "dropped, then
 * finished" stays one history entry (replaced in place). A container creates plays
 * only for its unsettled aired playable descendants in the active cycle:
 * already watched ones are never duplicated, skipped episodes and episodes
 * of skipped seasons stay deliberately settled.
 */
final readonly class MarkWatched
{
    public function __construct(
        private MediaTypeRegistry $types,
        private Hierarchy $hierarchy,
        private WatchCycleResolver $cycles,
        private Attempts $attempts,
        private MutationContext $mutations,
    ) {}

    /**
     * @param  int|null  $amount  real cost of this completion in the type's
     *                            progress unit (games: minutes played); when
     *                            omitted, the accumulated session progress is
     *                            frozen into the play.
     * @return int number of plays created or completed in place
     */
    public function execute(
        User $user,
        MediaItem $item,
        WatchedAt $watchedAt,
        ?int $amount = null,
        AttemptPolicy $policy = AttemptPolicy::ReopenDropped,
    ): int {
        // The read of the latest attempt, the play write, and the up-chain
        // resume are one unit — a mid-sequence failure must not leave a play
        // without its state/drop cleanup.
        return DB::transaction(
            fn (): int => $this->run($user, $item, $watchedAt, $amount, $policy)
        );
    }

    private function run(
        User $user,
        MediaItem $item,
        WatchedAt $watchedAt,
        ?int $amount,
        AttemptPolicy $policy,
    ): int {
        $chain = $this->hierarchy->ancestry($item);
        $root = $chain[count($chain) - 1];
        $cycleId = $this->cycles->activeFor($user, $root)?->id;
        $definition = $this->types->get($item->type);

        if ($definition->playable) {
            $at = $watchedAt->of($item);

            $latest = $this->attempts->latest($user, $item, $cycleId);

            if ($policy !== AttemptPolicy::NewAttempt && $latest?->outcome === PlayOutcome::Dropped) {
                // Reopen & complete the same attempt: the drop was a stage of
                // this attempt, not a fact of its own. Frozen hours and new
                // sessions merge unless an explicit total is given.
                $latest->update([
                    'outcome' => PlayOutcome::Completed,
                    'watched_at' => $at,
                    'amount' => $definition->progressUnit === null
                        ? null
                        : $amount ?? ($policy === AttemptPolicy::ReopenDropped
                            ? $this->mergedProgress($user, $item, $latest)
                            : $this->accumulatedProgress($user, $item)),
                ]);

                $play = $latest;
            } else {
                $play = Play::query()->create([
                    'user_id' => $user->id,
                    'media_item_id' => $item->id,
                    'watch_cycle_id' => $cycleId,
                    'watched_at' => $at,
                    'outcome' => PlayOutcome::Completed,
                    'amount' => $definition->progressUnit === null
                        ? null
                        : ($amount ?? $this->accumulatedProgress($user, $item)),
                ]);
            }

            $this->resumeAttempts($user, $item, $chain, $cycleId);

            $this->announce($user, $item, $root, [$play]);

            return 1;
        }

        $aired = $this->hierarchy->playableLeaves($item)->filter(
            fn (MediaItem $leaf) => $leaf->hasAired(today())
        );

        // ANY entry settles a leaf — a skipped episode stays skipped when its
        // season or show is marked watched — and so do skipped seasons
        // between the item and its leaves. Marking a skipped season itself is
        // the explicit override: its own drop no longer holds.
        $settlement = $this->attempts->settlement($user, Attempts::parents($aired), $cycleId)->withoutDropOf($item->id);

        // Row by row rather than one bulk insert: the event has to carry the
        // created plays with their ids, and re-selecting after an insert is
        // brittle when every row shares a timestamp. The loop runs inside the
        // transaction that is open anyway.
        $plays = $aired
            ->reject(fn (MediaItem $leaf) => $settlement->settles($leaf->id, $leaf->parent_id))
            ->map(fn (MediaItem $leaf): Play => Play::query()->create([
                'user_id' => $user->id,
                'media_item_id' => $leaf->id,
                'watch_cycle_id' => $cycleId,
                'watched_at' => $watchedAt->of($leaf),
                'outcome' => PlayOutcome::Completed,
            ]))
            ->values();

        if ($plays->isNotEmpty()) {
            $this->resumeAttempts($user, $item, $chain, $cycleId);

            $this->announce($user, $item, $root, array_values($plays->all()));
        }

        return $plays->count();
    }

    /**
     * One event for the whole cascade: marking a show watched can close
     * hundreds of episodes, and that is one intent, not hundreds of facts.
     *
     * @param  list<Play>  $plays
     */
    private function announce(User $user, MediaItem $item, MediaItem $root, array $plays): void
    {
        Event::dispatch(PlayRecorded::of($user->id, $this->mutations->current(), $item, $root, $plays));
    }

    /**
     * Session progress accumulated on the item so far (flat progress types),
     * read before resumeAttempts() deletes the state row.
     */
    private function accumulatedProgress(User $user, MediaItem $item): ?int
    {
        return Numbers::int(MediaUserState::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $item->id)
            ->value('progress'));
    }

    /**
     * Reopened attempt total: hours frozen at the drop plus sessions logged
     * since — the attempt was one continuous effort.
     */
    private function mergedProgress(User $user, MediaItem $item, Play $dropped): ?int
    {
        $total = ($this->accumulatedProgress($user, $item) ?? 0) + (int) $dropped->amount;

        return $total === 0 ? null : $total;
    }

    /**
     * A play settles attempts up the chain: open states on the item and its
     * root clear (watching IS resuming), the acted-on container and the root
     * lose their dropped play (that attempt is open again — history holds
     * only closed attempts), and an intermediate season's drop clears once
     * the season becomes fully watched (it cannot be completed AND dropped).
     * A playable item's own dropped plays are never touched here — reopening
     * those is the explicit in-place completion above.
     *
     * @param  list<MediaItem>  $chain
     */
    private function resumeAttempts(User $user, MediaItem $item, array $chain, ?int $cycleId): void
    {
        $root = $chain[count($chain) - 1];

        MediaUserState::query()
            ->where('user_id', $user->id)
            ->whereIn('media_item_id', array_unique([$item->id, $root->id]))
            ->delete();

        $containers = collect($chain)->filter(
            fn (MediaItem $node) => ! $this->types->get($node->type)->playable
        );

        $withDrops = Play::query()
            ->where('user_id', $user->id)
            ->where('outcome', PlayOutcome::Dropped->value)
            ->whereIn('media_item_id', $containers->pluck('id'))
            ->inCycle($cycleId)
            ->distinct()
            ->pluck('media_item_id');

        foreach ($containers as $container) {
            if (! $withDrops->contains($container->id)) {
                continue;
            }

            $explicit = $container->id === $item->id || $container->id === $root->id;

            if ($explicit || $this->attempts->fullyWatched($user, $container, $cycleId)) {
                Play::query()
                    ->where('user_id', $user->id)
                    ->where('media_item_id', $container->id)
                    ->where('outcome', PlayOutcome::Dropped->value)
                    ->inCycle($cycleId)
                    ->delete();
            }
        }
    }
}

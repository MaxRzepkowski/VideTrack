<?php

namespace App\Actions\Library;

use App\Catalog\Data\SubtreeNode;
use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Enums\PlayOutcome;
use App\Exceptions\NoOpenAttempt;
use App\Library\Attempts;
use App\Library\Events\PlayRecorded;
use App\Library\MutationContext;
use App\Library\WatchCycleResolver;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Closes the current attempt as dropped — a dated history entry, not a
 * sticky flag. Only an OPEN attempt can drop: no history yet (the implicit
 * first attempt), an open state (games: sessions / on hold), or a container
 * that is not completed. A title whose latest attempt in the active cycle
 * completed has nothing to drop. Dropping freezes accumulated session
 * progress into the play's amount (exactly like completing does) and clears
 * the open state.
 */
final readonly class DropAttempt
{
    public function __construct(
        private MediaTypeRegistry $types,
        private Hierarchy $hierarchy,
        private WatchCycleResolver $cycles,
        private Attempts $attempts,
        private MutationContext $mutations,
    ) {}

    /**
     * @param  int|null  $amount  hours already sunk into the attempt in the
     *                            type's progress unit; defaults to the open
     *                            state's accumulated sessions.
     */
    public function execute(User $user, MediaItem $item, WatchedAt $droppedAt, ?int $amount = null): void
    {
        // The dropped-play write and the open-state cleanup are one unit: a
        // mid-sequence failure must never leave a dropped play sitting next to
        // a still-open state (they contradict). Snapshot read included so the
        // decision runs against a consistent view.
        DB::transaction(function () use ($user, $item, $amount, $droppedAt): void {
            $root = $this->hierarchy->rootOf($item);
            $cycleId = $this->cycles->activeFor($user, $root)?->id;
            $state = MediaUserState::query()
                ->where('user_id', $user->id)
                ->where('media_item_id', $item->id)
                ->first();

            $latest = $this->attempts->latest($user, $item, $cycleId);

            // Already dropped with nothing new since — idempotent.
            if ($latest?->outcome === PlayOutcome::Dropped && $state === null) {
                return;
            }

            if (! $this->hasOpenAttempt($user, $item, $latest, $state, $cycleId)) {
                throw new NoOpenAttempt("Item [{$item->id}] has no open attempt to drop.");
            }

            $play = Play::query()->create([
                'user_id' => $user->id,
                'media_item_id' => $item->id,
                'watch_cycle_id' => $cycleId,
                'watched_at' => $droppedAt->of($item),
                'outcome' => PlayOutcome::Dropped,
                'amount' => $this->types->get($item->type)->progressUnit === null
                    ? null
                    : ($amount ?? $state?->progress),
            ]);

            $state?->delete();

            Event::dispatch(PlayRecorded::of($user->id, $this->mutations->current(), $item, $root, [$play]));
        });
    }

    private function hasOpenAttempt(
        User $user,
        MediaItem $item,
        ?Play $latest,
        ?MediaUserState $state,
        ?int $cycleId,
    ): bool {
        // Sessions / on hold / manual in-progress = an open attempt, even
        // right after a completion (games: playthrough 2 in progress).
        if ($state !== null) {
            return true;
        }

        $definition = $this->types->get($item->type);

        if ($definition->playable) {
            return $latest === null || $latest->outcome === PlayOutcome::Dropped;
        }

        $leaves = $this->hierarchy->playableNodes($item);
        $aired = $leaves->filter(fn (SubtreeNode $leaf): bool => $leaf->aired);

        // A container that still grows is an open attempt even when caught
        // up — dropping it means "not watching what comes next".
        if ($leaves->count() > $aired->count()) {
            return true;
        }

        if ($definition->isRoot()
            && $item->status?->hasEnded() !== true) {
            return true;
        }

        $parents = $aired->mapWithKeys(fn (SubtreeNode $leaf): array => [$leaf->id => $leaf->parentId])->all();

        return ! $this->attempts->settlement($user, $parents, $cycleId)->completes($parents);
    }
}

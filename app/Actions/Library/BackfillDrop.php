<?php

namespace App\Actions\Library;

use App\Catalog\Hierarchy;
use App\Enums\PlayOutcome;
use App\Library\BackfillBatch;
use App\Library\BackfillRules;
use App\Library\Events\PlayAmountChanged;
use App\Library\Events\PlayRecorded;
use App\Library\MutationContext;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * An attempt the user's history elsewhere says was abandoned — added as a
 * dated fact, in the run under way on its date. It does not stand when the
 * position already carries a drop in that run, or when a completed viewing
 * dated after it says the user went on since ({@see BackfillRules::completedAfter()}:
 * for a show, only the viewings the library held before the batch). A pause
 * set before it closes with it, as dropping does.
 */
final readonly class BackfillDrop
{
    public function __construct(
        private Hierarchy $hierarchy,
        private BackfillRules $rules,
        private MutationContext $mutations,
    ) {}

    /**
     * @param  int|null  $amount  what the attempt had cost, in the type's progress unit
     * @return bool whether a new entry was written
     */
    public function execute(User $user, MediaItem $item, WatchedAt $droppedAt, BackfillBatch $batch, ?int $amount = null): bool
    {
        return DB::transaction(function () use ($user, $item, $droppedAt, $batch, $amount): bool {
            $at = $droppedAt->of($item);
            $root = $this->hierarchy->rootOf($item);
            $cycleId = $this->rules->cycleAt($user, $root, $at);
            $kept = $this->rules->amount($item, $amount);

            $drop = Play::query()
                ->where('user_id', $user->id)
                ->where('media_item_id', $item->id)
                ->where('outcome', PlayOutcome::Dropped->value)
                ->inCycle($cycleId)
                ->latest('watched_at')
                ->first();

            if ($drop !== null) {
                // The drop exists — but a missing frozen counter may still
                // fill in when a re-import brings it.
                if ($drop->amount === null && $kept !== null) {
                    $drop->update(['amount' => $kept]);
                    Event::dispatch(new PlayAmountChanged(userId: $user->id, source: $this->mutations->current(), itemId: $item->id, rootId: $root->id, playId: $drop->id, amount: $kept, previous: null));
                }

                return false;
            }

            if ($this->rules->completedAfter($user, $item, $cycleId, $at, $batch)) {
                return false;
            }

            $play = Play::query()->create([
                'user_id' => $user->id,
                'media_item_id' => $item->id,
                'watch_cycle_id' => $cycleId,
                'watched_at' => $at,
                'outcome' => PlayOutcome::Dropped,
                'amount' => $kept,
            ]);

            MediaUserState::query()
                ->where('user_id', $user->id)
                ->where('media_item_id', $item->id)
                ->where('updated_at', '<', $at)
                ->delete();

            Event::dispatch(PlayRecorded::of($user->id, $this->mutations->current(), $item, $root, [$play]));

            return true;
        });
    }
}

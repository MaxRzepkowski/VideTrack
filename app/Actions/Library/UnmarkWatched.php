<?php

namespace App\Actions\Library;

use App\Catalog\Hierarchy;
use App\Library\Events\MediaStatusChanged;
use App\Library\Events\PlayRemoved;
use App\Library\LibraryProjector;
use App\Library\MutationContext;
use App\Library\WatchCycleResolver;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The downward unmark cascade — symmetric to marking:
 * a position becomes unwatched in the active cycle, so that cycle's entries
 * are deleted — plays of every playable descendant AND drops sitting on the
 * item or its container nodes (a skipped season must not keep a "reset" show
 * in progress forever). Earlier cycles remain immutable history. The UI
 * confirms the active-cycle count beforehand.
 * Specials stay untouched when unmarking a show, exactly like when marking.
 */
final readonly class UnmarkWatched
{
    public function __construct(
        private Hierarchy $hierarchy,
        private WatchCycleResolver $cycles,
        private MutationContext $mutations,
        private LibraryProjector $projector,
    ) {}

    /**
     * @return int number of plays deleted
     */
    public function execute(User $user, MediaItem $item): int
    {
        $root = $this->hierarchy->rootOf($item);
        $ids = $this->hierarchy->cascadeIds($item);

        // One click, one projection: the status and play events it announces
        // all move the same title.
        return $this->projector->deferring($user, fn (): int => DB::transaction(function () use ($user, $item, $root, $ids): int {
            $cycleId = $this->cycles->activeFor($user, $root)?->id;

            // Symmetric to marking: unmarking resets THIS item to a clean
            // slate, so its own open-attempt state (on_hold / in-progress
            // counter) is cleared alongside its plays. (Setting on_hold with no
            // plays remains a valid standalone status elsewhere.)
            $states = MediaUserState::query()
                ->where('user_id', $user->id)
                ->whereIn('media_item_id', $ids)
                ->get();

            MediaUserState::query()->whereKey($states->modelKeys())->delete();

            foreach ($states as $state) {
                Event::dispatch(new MediaStatusChanged(
                    userId: $user->id,
                    source: $this->mutations->current(),
                    itemId: $state->media_item_id,
                    status: null,
                    previous: $state->status,
                ));
            }

            // Collected before the delete: the event names the entries that
            // disappeared, and afterwards there is nothing left to name.
            $playIds = collect(Numbers::ints(Play::query()
                ->where('user_id', $user->id)
                ->whereIn('media_item_id', $ids)
                ->inCycle($cycleId)
                ->pluck('id')));

            if ($playIds->isEmpty()) {
                return 0;
            }

            Play::query()->whereKey($playIds)->delete();

            Event::dispatch(new PlayRemoved(
                userId: $user->id,
                source: $this->mutations->current(),
                itemId: $item->id,
                rootId: $root->id,
                playIds: array_values($playIds->all()),
            ));

            return $playIds->count();
        }));
    }
}

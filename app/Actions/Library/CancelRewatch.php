<?php

namespace App\Actions\Library;

use App\Catalog\MediaTypeRegistry;
use App\Exceptions\NoRewatchToCancel;
use App\Library\Events\PlayRemoved;
use App\Library\Events\RewatchCancelled;
use App\Library\MutationContext;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use App\Models\WatchCycle;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Takes back the newest reset: the plays collected since it are deleted and
 * the run before it stands with its history as it was. A pause it carried is
 * not brought back: the reset cleared it ({@see StartRewatch}), and a status
 * is the user's to set again. Deliberately destructive: the alternative,
 * folding those plays into the preceding cycle, would merge two viewings into
 * one with no way of ever telling them apart. The UI
 * says what disappears and asks twice.
 *
 * Only the newest cycle can be cancelled, so history is peeled one layer at a
 * time and no earlier cycle is ever touched.
 */
final readonly class CancelRewatch
{
    public function __construct(
        private MediaTypeRegistry $types,
        private MutationContext $mutations,
    ) {}

    /**
     * @return int number of plays deleted
     */
    public function execute(User $user, MediaItem $root): int
    {
        $definition = $this->types->get($root->type);

        if (! $definition->isRoot() || $definition->childType === null || $root->parent_id !== null) {
            throw new NoRewatchToCancel("Item [{$root->id}] is not a container root.");
        }

        return DB::transaction(function () use ($user, $root): int {
            $active = WatchCycle::query()
                ->where('user_id', $user->id)
                ->where('media_item_id', $root->id)
                ->orderByDesc('number')
                ->first();

            if ($active === null) {
                throw new NoRewatchToCancel("Item [{$root->id}] has no rewatch to cancel.");
            }

            // Every play carrying this cycle belongs to the root's subtree by
            // construction (the stamp is set from the root); the user filter
            // is belt and braces.
            $playIds = collect(Numbers::ints(Play::query()
                ->where('user_id', $user->id)
                ->where('watch_cycle_id', $active->id)
                ->pluck('id')));

            Play::query()->whereKey($playIds)->delete();
            $active->delete();

            $source = $this->mutations->current();

            if ($playIds->isNotEmpty()) {
                Event::dispatch(new PlayRemoved(
                    userId: $user->id,
                    source: $source,
                    itemId: $root->id,
                    rootId: $root->id,
                    playIds: array_values($playIds->all()),
                ));
            }

            Event::dispatch(new RewatchCancelled(userId: $user->id, source: $source, rootId: $root->id, cycle: $active->number));

            return $playIds->count();
        });
    }
}

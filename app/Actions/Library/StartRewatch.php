<?php

namespace App\Actions\Library;

use App\Catalog\Hierarchy;
use App\Catalog\MediaTypeRegistry;
use App\Exceptions\CannotStartRewatch;
use App\Library\Events\MediaStatusChanged;
use App\Library\Events\RewatchStarted;
use App\Library\MutationContext;
use App\Library\WatchCycleResolver;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\User;
use App\Models\WatchCycle;
use App\Support\Numbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final readonly class StartRewatch
{
    public function __construct(
        private MediaTypeRegistry $types,
        private Hierarchy $hierarchy,
        private WatchCycleResolver $cycles,
        private MutationContext $mutations,
    ) {}

    public function execute(User $user, MediaItem $root): WatchCycle
    {
        $definition = $this->types->get($root->type);

        if (! $definition->isRoot() || $definition->childType === null || $root->parent_id !== null) {
            throw new CannotStartRewatch("Item [{$root->id}] is not a container root.");
        }

        return DB::transaction(function () use ($user, $root): WatchCycle {
            $active = $this->cycles->activeFor($user, $root);

            // Every node belongs to the root's cycle, specials and dropped
            // containers included, so any interaction in the cycle counts.
            $hasPlay = Play::query()
                ->where('user_id', $user->id)
                ->whereIn('media_item_id', $this->hierarchy->subtreeIds($root))
                ->inCycle($active?->id)
                ->exists();

            if (! $hasPlay) {
                throw new CannotStartRewatch("Item [{$root->id}] has no plays in its active cycle.");
            }

            $latestNumber = Numbers::int(WatchCycle::query()
                ->where('user_id', $user->id)
                ->where('media_item_id', $root->id)
                ->max('number')) ?? 0;

            $cycle = WatchCycle::query()->create([
                'user_id' => $user->id,
                'media_item_id' => $root->id,
                'number' => max(1, $latestNumber) + 1,
                'started_at' => now(),
            ]);

            // A new run starts with nothing open: a pause of the last one does
            // not carry over.
            $state = MediaUserState::query()
                ->where('user_id', $user->id)
                ->where('media_item_id', $root->id)
                ->first();
            $state?->delete();

            Event::dispatch(new RewatchStarted(userId: $user->id, source: $this->mutations->current(), rootId: $root->id, cycle: $cycle->number));

            if ($state !== null) {
                Event::dispatch(new MediaStatusChanged(userId: $user->id, source: $this->mutations->current(), itemId: $root->id, status: null, previous: $state->status));
            }

            return $cycle;
        });
    }
}

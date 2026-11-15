<?php

namespace App\Actions\Library;

use App\Catalog\Hierarchy;
use App\Enums\PlayOutcome;
use App\Library\Attempts;
use App\Library\Events\PlayRemoved;
use App\Library\MutationContext;
use App\Library\WatchCycleResolver;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * Un-drop: deletes the latest entry of the position in its run when it is a
 * drop, which opens that attempt again. History is only ever rewritten here
 * and in the plays panel — never implicitly.
 */
final readonly class UndoDrop
{
    public function __construct(
        private Hierarchy $hierarchy,
        private WatchCycleResolver $cycles,
        private Attempts $attempts,
        private MutationContext $mutations,
    ) {}

    public function execute(User $user, MediaItem $item): void
    {
        $root = $this->hierarchy->rootOf($item);
        $latest = $this->attempts->latest($user, $item, $this->cycles->activeFor($user, $root)?->id);

        if ($latest?->outcome !== PlayOutcome::Dropped) {
            return;
        }

        $playId = $latest->id;
        $latest->delete();

        Event::dispatch(new PlayRemoved(
            userId: $user->id,
            source: $this->mutations->current(),
            itemId: $item->id,
            rootId: $root->id,
            playIds: [$playId],
        ));
    }
}

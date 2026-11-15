<?php

namespace App\Actions\Library;

use App\Catalog\Hierarchy;
use App\Library\Events\PlayRemoved;
use App\Library\MutationContext;
use App\Models\Play;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * Removes one specific viewing from the history — the plays panel unmarks
 * a single attempt, not the whole position. Ownership is settled by the
 * caller; this is the write, and the only place it is announced.
 */
final readonly class DeletePlay
{
    public function __construct(
        private Hierarchy $hierarchy,
        private MutationContext $mutations,
    ) {}

    public function execute(User $user, Play $play): void
    {
        $itemId = $play->media_item_id;
        $playId = $play->id;
        $rootId = $this->hierarchy->rootOf($play->mediaItem)->id;

        $play->delete();

        Event::dispatch(new PlayRemoved(
            userId: $user->id,
            source: $this->mutations->current(),
            itemId: $itemId,
            rootId: $rootId,
            playIds: [$playId],
        ));
    }
}

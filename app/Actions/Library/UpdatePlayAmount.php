<?php

namespace App\Actions\Library;

use App\Catalog\Hierarchy;
use App\Library\Events\PlayAmountChanged;
use App\Library\MutationContext;
use App\Models\Play;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * Corrects the recorded cost of one closed attempt (games: hours of that
 * playthrough) — e.g. post-game content played after completing.
 */
final readonly class UpdatePlayAmount
{
    public function __construct(
        private Hierarchy $hierarchy,
        private MutationContext $mutations,
    ) {}

    public function execute(User $user, Play $play, ?int $amount): void
    {
        $previous = $play->amount === null ? null : (int) $play->amount;

        if ($previous === $amount) {
            return;
        }

        $play->update(['amount' => $amount]);

        Event::dispatch(new PlayAmountChanged(
            userId: $user->id,
            source: $this->mutations->current(),
            itemId: $play->media_item_id,
            rootId: $this->hierarchy->rootOf($play->mediaItem)->id,
            playId: $play->id,
            amount: $amount,
            previous: $previous,
        ));
    }
}

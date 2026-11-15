<?php

namespace App\Actions\Library;

use App\Library\Events\MediaStatusChanged;
use App\Library\MutationContext;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/** Throws the open attempt away: its status and the sessions logged in it. */
final readonly class DiscardAttempt
{
    public function __construct(private MutationContext $mutations) {}

    public function execute(User $user, MediaItem $item): void
    {
        $state = MediaUserState::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $item->id)
            ->first();

        if ($state === null) {
            return;
        }

        $state->delete();

        Event::dispatch(new MediaStatusChanged(
            userId: $user->id,
            source: $this->mutations->current(),
            itemId: $item->id,
            status: null,
            previous: $state->status,
        ));
    }
}

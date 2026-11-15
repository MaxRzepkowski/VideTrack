<?php

namespace App\Actions\Library;

use App\Enums\FollowMode;
use App\Library\Events\FollowChanged;
use App\Library\MutationContext;
use App\Library\Sponsorships;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Writes the bell on a title: `follow` / `mute` upserts the exception row,
 * null hands the title back to whatever the tracking matrix says.
 */
final readonly class SetFollow
{
    public function __construct(
        private Sponsorships $sponsorships,
        private MutationContext $mutations,
    ) {}

    public function execute(User $user, MediaItem $item, ?FollowMode $mode): void
    {
        DB::transaction(function () use ($user, $item, $mode): void {
            $row = $user->follows()->where('media_item_id', $item->id)->first();
            $previous = $row?->mode;

            if ($mode === null) {
                if ($row === null) {
                    return;
                }

                // A row a followed creator backs goes back to being its
                // auto-follow — deleting it would drop the title although its
                // sponsors are still followed (mute → unmute must round-trip).
                // Only the user's own bell disappears.
                if ($this->sponsorships->isSponsored($row)) {
                    $row->update(['mode' => FollowMode::Follow]);
                    $mode = FollowMode::Follow;
                } else {
                    $row->delete();
                }
            } else {
                $row = $user->follows()->updateOrCreate(['media_item_id' => $item->id], ['mode' => $mode]);

                // A FOLLOW makes the bell the user's own: unfollowing a sponsor
                // later keeps it. A MUTE overrides without taking over — the
                // sponsors stay, so lifting it restores the auto-follow.
                if ($mode === FollowMode::Follow) {
                    $this->sponsorships->release($row);
                }
            }

            if ($mode !== $previous) {
                Event::dispatch(new FollowChanged(
                    userId: $user->id,
                    source: $this->mutations->current(),
                    mode: $mode,
                    previousMode: $previous,
                    itemId: $item->id,
                ));
            }
        });
    }
}

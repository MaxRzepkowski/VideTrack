<?php

namespace App\Actions\Library;

use App\Catalog\MediaTypeRegistry;
use App\Enums\MediaUserStatus;
use App\Library\Events\MediaStatusChanged;
use App\Library\MutationContext;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use LogicException;

/**
 * State of the OPEN attempt (on hold / manual in-progress): one row per
 * user + root. Closed attempts — completed or dropped — are play entries
 * handled by MarkWatched / DropAttempt instead.
 *
 * Clearing the status RESUMES the attempt, it does not throw it away: the
 * row also carries accumulated session progress, and someone who paused a
 * game after two hours paused the playing, not the two hours. Discarding
 * the whole attempt is {@see DiscardAttempt} — a separate, explicit request.
 */
final readonly class SetMediaStatus
{
    public function __construct(
        private MediaTypeRegistry $types,
        private MutationContext $mutations,
    ) {}

    public function execute(User $user, MediaItem $item, ?MediaUserStatus $status): void
    {
        if ($status === null) {
            $state = MediaUserState::query()
                ->where('user_id', $user->id)
                ->where('media_item_id', $item->id)
                ->first();

            if ($state === null) {
                return;
            }

            $previous = $state->status;

            // Sessions survive the hold; with none logged the row carried
            // nothing but the status and has no reason to stay.
            if ((int) ($state->progress ?? 0) > 0) {
                $state->status = MediaUserStatus::InProgress;
                $state->save();

                $this->announce($user, $item, MediaUserStatus::InProgress, $previous);

                return;
            }

            $state->delete();

            $this->announce($user, $item, null, $previous);

            return;
        }

        // The request refuses it already; a caller that skipped the request
        // must not write a state nothing reads.
        if (! $this->types->get($item->type)->takesOpenStatus()) {
            throw new LogicException("A [{$item->type}] holds no open attempt of its own.");
        }

        $previous = $this->currentStatus($user, $item);

        MediaUserState::query()->updateOrCreate(
            ['user_id' => $user->id, 'media_item_id' => $item->id],
            ['status' => $status],
        );

        $this->announce($user, $item, $status, $previous);
    }

    private function currentStatus(User $user, MediaItem $item): ?MediaUserStatus
    {
        $status = MediaUserState::query()
            ->where('user_id', $user->id)
            ->where('media_item_id', $item->id)
            ->value('status');

        return $status instanceof MediaUserStatus ? $status : null;
    }

    private function announce(
        User $user,
        MediaItem $item,
        ?MediaUserStatus $status,
        ?MediaUserStatus $previous,
    ): void {
        // Re-sending the status the attempt already carries changed nothing.
        if ($status === $previous) {
            return;
        }

        Event::dispatch(new MediaStatusChanged(
            userId: $user->id,
            source: $this->mutations->current(),
            itemId: $item->id,
            status: $status,
            previous: $previous,
        ));
    }
}

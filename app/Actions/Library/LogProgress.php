<?php

namespace App\Actions\Library;

use App\Catalog\ProgressTotals;
use App\Enums\MediaUserStatus;
use App\Library\Events\ProgressLogged;
use App\Library\MutationContext;
use App\Library\WatchedAt;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Session progress on flat progress types (games). A session (`add`)
 * accumulates minutes and always flips the status to in-progress — logging
 * playtime IS playing, even on a dropped or on-hold title. An absolute edit
 * (`set`, the pencil editor) only corrects the counter and leaves the status
 * alone. Completing the title later moves the counter into the play's
 * `amount` (MarkWatched) and clears this row.
 *
 * On positional types with a real maximum (books/manga/comics), reaching the
 * last page/chapter/issue IS finishing the title: the counter is frozen into a
 * completed play and this row cleared, so a title parked at its maximum lands
 * in the completed history instead of staying "in progress" forever. Flat
 * types (games: minutes) have no maximum and never auto-complete.
 */
final readonly class LogProgress
{
    public function __construct(
        private MarkWatched $markWatched,
        private MutationContext $mutations,
        private ProgressTotals $totals,
    ) {}

    public function execute(User $user, MediaItem $item, ?int $add, ?int $set): void
    {
        // Positional types (books/manga/comics) carry a real maximum
        // (pages/chapters/issues); the counter is clamped to it so a stray
        // `set=99999` can't overshoot and later inflate the frozen play amount.
        // Games (minutes) have no cap, so nothing clamps them.
        $cap = $this->totals->of($item);

        // Read-modify-write is serialized in a transaction so concurrent
        // sessions don't lose an increment, and a racing first insert can't
        // trip the unique(user_id, media_item_id) constraint.
        DB::transaction(function () use ($user, $item, $add, $set, $cap): void {
            $state = MediaUserState::query()
                ->where('user_id', $user->id)
                ->where('media_item_id', $item->id)
                ->lockForUpdate()
                ->first()
                ?? new MediaUserState([
                    'user_id' => $user->id,
                    'media_item_id' => $item->id,
                ]);

            if ($add !== null) {
                $state->progress = $this->clamp((int) ($state->progress ?? 0) + $add, $cap);
                $state->status = MediaUserStatus::InProgress;
            }

            if ($set !== null) {
                $state->progress = $this->clamp($set, $cap);
                $state->status ??= MediaUserStatus::InProgress;
            }

            // Reaching the maximum finishes the title: freeze the full count
            // into a completed play (stamped now — the reader just finished)
            // and let MarkWatched clear this state row. Flat types have no cap.
            if ($cap !== null && $state->progress >= $cap) {
                // Finishing is a play, not a session — MarkWatched announces
                // it, and a second event for the same intent would be noise.
                $this->markWatched->execute($user, $item, WatchedAt::now(), $cap);

                return;
            }

            $state->save();

            Event::dispatch(new ProgressLogged(
                userId: $user->id,
                source: $this->mutations->current(),
                itemId: $item->id,
                added: $add,
                set: $set,
                progress: (int) $state->progress,
            ));
        });
    }

    private function clamp(int $value, ?int $cap): int
    {
        $value = max(0, $value);

        return $cap === null ? $value : min($value, $cap);
    }
}

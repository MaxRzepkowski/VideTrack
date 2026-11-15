<?php

namespace App\Actions\Library;

use App\Library\Events\LibraryCleared;
use App\Library\LibraryProjector;
use App\Library\MutationContext;
use App\Models\HiddenEvent;
use App\Models\RecentView;
use App\Models\User;
use App\Models\WatchCycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Wipes the whole library of one user (settings danger zone): plays and
 * rewatch cycles, ratings, favorites, viewing statuses, follows (bells on
 * titles and creators), every list and the recently viewed titles. The
 * account, its preferences, API tokens, webhooks and connected accounts stay;
 * a connected account forgets how far it had read, so its next import fills
 * the cleared library anew. List items and follow sponsors leave with their
 * parent rows on a foreign-key cascade. The watchlist is part of the account,
 * so it stays, emptied.
 */
final readonly class ClearUserData
{
    /** Every table keyed by the user that the wipe empties for them (the watchlist row itself stays, emptied). */
    public const CLEARED = ['plays', 'watch_cycles', 'ratings', 'favorites', 'media_user_states', 'library_entries', 'follows', 'hidden_events', 'lists', 'recent_views'];

    /** Tables keyed by the user that belong to the account, not to the library. */
    public const KEPT = ['service_connections', 'webhook_endpoints', 'task_runs', 'sessions'];

    public function __construct(
        private MutationContext $mutations,
        private LibraryProjector $projector,
    ) {}

    public function execute(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->plays()->delete();
            WatchCycle::query()->where('user_id', $user->id)->delete();
            $user->ratings()->delete();
            $user->favorites()->delete();
            $user->mediaStates()->delete();
            $this->projector->forget($user);
            $user->follows()->delete();
            // Like a bell, an event taken off the calendar is a tracking choice.
            HiddenEvent::query()->where('user_id', $user->id)->delete();
            $user->lists()->whereNull('system_key')->delete();
            $user->watchlist()->items()->delete();
            RecentView::query()->where('user_id', $user->id)->delete();
            $user->serviceConnections()->update(['state' => null]);

            // One intent, one fact: consumers rebuild from nothing rather than
            // hear of every row that went.
            Event::dispatch(new LibraryCleared(userId: $user->id, source: $this->mutations->current()));
        });
    }
}

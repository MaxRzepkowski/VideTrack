<?php

namespace App\Library;

use App\Enums\LibraryTab;
use App\Enums\PlayOutcome;
use App\Models\Favorite;
use App\Models\MediaItem;
use App\Models\MediaUserState;
use App\Models\Play;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The positions behind one tab of a user's library, newest first: what they
 * finished, rated, favourited, or have open. A plain, stable order a client
 * can page through.
 */
final readonly class LibraryTabQuery
{
    /**
     * @return Builder<MediaItem>
     */
    public function of(User $viewer, LibraryTab $tab, ?string $type): Builder
    {
        $userId = $viewer->id;

        $query = MediaItem::query()
            // Context summaries read the show and season above an episode.
            ->with('parent.parent')
            ->when(
                $type !== null,
                fn (Builder $query) => $query->where('media_items.type', $type)
            );

        return match ($tab) {
            // Latest play first — a position appears once, however many
            // times it was watched, and only once it was completed. One
            // grouped pass over the plays, not a lookup per title.
            LibraryTab::History => $query
                ->joinSub(
                    Play::query()
                        ->where('user_id', $userId)
                        ->groupBy('media_item_id')
                        ->havingRaw('max(outcome = ?) = 1', [PlayOutcome::Completed->value])
                        ->select('media_item_id')
                        ->selectRaw('max(watched_at) as last_played_at'),
                    'history',
                    'history.media_item_id',
                    '=',
                    'media_items.id',
                )
                ->select('media_items.*')
                ->orderByDesc('history.last_played_at'),
            LibraryTab::Ratings => $query
                ->whereIn('id', Rating::query()->where('user_id', $userId)->select('media_item_id'))
                ->orderByDesc(Rating::query()
                    ->whereColumn('ratings.media_item_id', 'media_items.id')
                    ->where('ratings.user_id', $userId)
                    ->select('ratings.updated_at')),
            LibraryTab::Favorites => $query
                ->whereIn('id', Favorite::query()->where('user_id', $userId)->select('media_item_id'))
                ->orderByDesc(Favorite::query()
                    ->whereColumn('favorites.media_item_id', 'media_items.id')
                    ->where('favorites.user_id', $userId)
                    ->select('favorites.created_at')),
            // Open attempts: paused or actively played, the counter included.
            LibraryTab::Progress => $query
                ->whereIn('id', MediaUserState::query()->where('user_id', $userId)->select('media_item_id'))
                ->orderByDesc(MediaUserState::query()
                    ->whereColumn('media_user_states.media_item_id', 'media_items.id')
                    ->where('media_user_states.user_id', $userId)
                    ->select('media_user_states.updated_at')),
        };
    }
}

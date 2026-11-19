<?php

namespace App\Http\Controllers\Users;

use App\Accounts\UserCalendar;
use App\Actions\Users\BuildActivityPanel;
use App\Catalog\MediaItemSummaries;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Users\Concerns\SharesProfileHeader;
use App\Http\Requests\Users\ActivityPanelRequest;
use App\Models\Favorite;
use App\Models\User;
use App\Users\ActivityFeed;
use App\Users\ActivitySource;
use App\Users\Data\ActivityEntry;
use App\Users\OwnerRatings;
use App\Users\PanelReader;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The favorites panel of a user view. Unlike history and ratings this is a
 * collection rather than a feed — when you favorited something matters far
 * less than what is in it, so it opens as a grid.
 */
class UserFavoritesController extends Controller
{
    use SharesProfileHeader;

    public function __invoke(
        #[CurrentUser] User $viewer,
        ActivityPanelRequest $request,
        User $user,
        ActivityFeed $feed,
        BuildActivityPanel $panel,
        MediaItemSummaries $cards,
        OwnerRatings $ownerRatings,
    ): Response {
        Gate::authorize('view', $user);

        $reader = PanelReader::of($viewer, $user);
        $filters = $request->activityFilters(ActivitySource::Favorites, $reader);
        $page = $feed->page(ActivitySource::Favorites, $user, $viewer, $filters, $request->page(), UserCalendar::of($viewer));

        /** @var Collection<int, Favorite> $favorites */
        $favorites = $page['models'];
        $ratings = $ownerRatings->shownUnder($user, $reader, $filters, $favorites->map(fn (Favorite $favorite) => $favorite->mediaItem));
        $entries = $favorites
            ->map(fn (Favorite $favorite): ActivityEntry => new ActivityEntry(
                id: $favorite->id,
                at: $favorite->created_at?->toIso8601String(),
                media: $cards->inContext($favorite->mediaItem),
                day: $page['dayOf'][$favorite->id] ?? null,
                rating: $ratings[$favorite->media_item_id] ?? null,
            ))
            ->values()
            ->all();

        return Inertia::render('users/UserActivity', [
            'panel' => 'favorites',
            ...$this->profileHeader($viewer, $user),
            ...$panel->execute($viewer, $user, ActivitySource::Favorites, $entries, $page['pagination'], $filters),
        ]);
    }
}

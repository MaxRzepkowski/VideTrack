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
    ): Response {
        Gate::authorize('view', $user);

        $filters = $request->activityFilters(ActivitySource::Favorites);
        $page = $feed->page(ActivitySource::Favorites, $user, $filters, $request->page(), UserCalendar::of($viewer));

        /** @var Collection<int, Favorite> $favorites */
        $favorites = $page['models'];
        $entries = $favorites
            ->map(fn (Favorite $favorite): array => [
                'id' => $favorite->id,
                'at' => $favorite->created_at?->toIso8601String(),
                'day' => $page['dayOf'][$favorite->id] ?? null,
                'media' => $cards->inContext($favorite->mediaItem),
            ])
            ->values()
            ->all();

        return Inertia::render('users/UserActivity', [
            'panel' => 'favorites',
            ...$this->profileHeader($viewer, $user),
            ...$panel->execute($viewer, $user, $entries, $page['pagination'], $filters),
        ]);
    }
}

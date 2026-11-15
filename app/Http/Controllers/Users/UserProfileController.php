<?php

namespace App\Http\Controllers\Users;

use App\Actions\Media\BuildWatchStats;
use App\Catalog\MediaItemSummaries;
use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserProfileController extends Controller
{
    /** One row of the shelf grid at its widest. */
    private const FAVORITES_SHELF = 5;

    use Concerns\SharesProfileHeader;

    public function __invoke(
        #[CurrentUser] User $viewer,
        User $user,
        BuildWatchStats $stats,
        MediaItemSummaries $cards,
    ): Response {
        Gate::authorize('view', $user);

        return Inertia::render('users/UserProfile', [
            ...$this->profileHeader($viewer, $user),
            'favoritesCount' => $user->favorites()->count(),
            // One row of the shelf grid at its widest, so the preview never
            // spills a lone card onto a second row. "See all" has the rest.
            'favorites' => $user->favorites()->with('mediaItem.parent.parent')->latest('created_at')->limit(self::FAVORITES_SHELF)->get()
                ->map(fn (Favorite $favorite) => $cards->inContext($favorite->mediaItem)),
            // The same tiles the dashboard opens with, counted for the user
            // whose profile this is — a visitor reads their numbers, not their
            // own.
            'stats' => $stats->execute($user),
        ]);
    }
}

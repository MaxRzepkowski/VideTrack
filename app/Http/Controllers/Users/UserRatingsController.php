<?php

namespace App\Http\Controllers\Users;

use App\Accounts\UserCalendar;
use App\Actions\Users\BuildActivityPanel;
use App\Catalog\MediaItemSummaries;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Users\Concerns\SharesProfileHeader;
use App\Http\Requests\Users\ActivityPanelRequest;
use App\Models\Rating;
use App\Models\User;
use App\Users\ActivityFeed;
use App\Users\ActivitySource;
use App\Users\Data\ActivityEntry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The ratings panel of a user view: every score they gave, as a grid running
 * from the highest down. A panel about scores opens on the scores — dated
 * order is what the history is for, and it stays one pick away.
 *
 * A rating is dated by its last edit, so re-scoring a title moves it to the
 * top of the feed under the "Activity date" sort.
 */
class UserRatingsController extends Controller
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

        $filters = $request->activityFilters(ActivitySource::Ratings);
        $page = $feed->page(ActivitySource::Ratings, $user, $filters, $request->page(), UserCalendar::of($viewer));

        /** @var Collection<int, Rating> $ratings */
        $ratings = $page['models'];
        $entries = $ratings
            ->map(fn (Rating $rating): ActivityEntry => new ActivityEntry(
                id: $rating->id,
                at: $rating->updated_at?->toIso8601String(),
                media: $cards->inContext($rating->mediaItem),
                day: $page['dayOf'][$rating->id] ?? null,
                rating: $rating->rating,
            ))
            ->values()
            ->all();

        return Inertia::render('users/UserActivity', [
            'panel' => 'ratings',
            ...$this->profileHeader($viewer, $user),
            ...$panel->execute($viewer, $user, $entries, $page['pagination'], $filters),
        ]);
    }
}

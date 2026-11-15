<?php

namespace App\Http\Controllers\Users;

use App\Accounts\UserCalendar;
use App\Actions\Users\BuildActivityPanel;
use App\Catalog\MediaItemSummaries;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Users\Concerns\SharesProfileHeader;
use App\Http\Requests\Users\ActivityPanelRequest;
use App\Models\Play;
use App\Models\User;
use App\Users\ActivityFeed;
use App\Users\ActivitySource;
use App\Users\HistoryDayTotals;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The history panel of a user view: every CLOSED attempt at a position, the
 * completed ones and the dropped ones alike. It is the only panel whose
 * entries carry a cost, so it is the only one that totals up its days.
 */
class UserHistoryController extends Controller
{
    use SharesProfileHeader;

    public function __invoke(
        #[CurrentUser] User $viewer,
        ActivityPanelRequest $request,
        User $user,
        ActivityFeed $feed,
        BuildActivityPanel $panel,
        HistoryDayTotals $totals,
        MediaItemSummaries $cards,
    ): Response {
        Gate::authorize('view', $user);

        $filters = $request->activityFilters(ActivitySource::Plays);
        $calendar = UserCalendar::of($viewer);

        // Filtering by a container type (shows, seasons) collapses the plays
        // into the containers themselves — see ActivityFeed::rollUpType. A
        // container is not a cost, so its days carry no totals.
        if (($rollUp = $feed->rollUpType($filters)) !== null) {
            $page = $feed->groupedPage(ActivitySource::Plays, $user, $filters, $request->page(), $calendar, $rollUp);

            $entries = array_map(fn (array $group): array => [
                // No single play behind a card — nothing to remove from here.
                'id' => null,
                'at' => $group['at'],
                'day' => $group['day'],
                'media' => $cards->inContext($group['item']),
            ], $page['groups']);
            $dayTotals = [];
        } else {
            // The day scan needs what a play cost, on top of its date.
            $page = $feed->page(ActivitySource::Plays, $user, $filters, $request->page(), $calendar, $totals->columns());

            /** @var EloquentCollection<int, Play> $plays */
            $plays = $page['models'];
            $entries = $plays
                ->map(fn (Play $play): array => [
                    'id' => $play->id,
                    'at' => $play->watched_at->toIso8601String(),
                    'day' => $page['dayOf'][$play->id] ?? null,
                    'media' => $cards->inContext($play->mediaItem),
                    'outcome' => $play->outcome->value,
                ])
                ->values()
                ->all();
            $dayTotals = $totals->of($page['dayRows'], $page['pageDays']);
        }

        return Inertia::render('users/UserActivity', [
            'panel' => 'history',
            ...$this->profileHeader($viewer, $user),
            ...$panel->execute($viewer, $user, $entries, $page['pagination'], $filters),
            // What each day on the page cost, per unit — for the day headings.
            'dayTotals' => $dayTotals,
        ]);
    }
}

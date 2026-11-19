<?php

namespace App\Http\Controllers\Users;

use App\Accounts\UserCalendar;
use App\Actions\Users\BuildActivityPanel;
use App\Actions\Users\BuildHistoryEntries;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Users\Concerns\SharesProfileHeader;
use App\Http\Requests\Users\ActivityPanelRequest;
use App\Models\User;
use App\Users\ActivitySource;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The history panel of a user view: every CLOSED attempt at a position, the
 * completed ones and the dropped ones alike, or the shows and seasons they
 * roll up into ({@see BuildHistoryEntries}).
 */
class UserHistoryController extends Controller
{
    use SharesProfileHeader;

    public function __invoke(
        #[CurrentUser] User $viewer,
        ActivityPanelRequest $request,
        User $user,
        BuildHistoryEntries $history,
        BuildActivityPanel $panel,
    ): Response {
        Gate::authorize('view', $user);

        $filters = $request->activityFilters(ActivitySource::Plays);
        $page = $history->execute($user, $filters, $request->page(), UserCalendar::of($viewer));

        return Inertia::render('users/UserActivity', [
            'panel' => 'history',
            ...$this->profileHeader($viewer, $user),
            ...$panel->execute($viewer, $user, $page['entries'], $page['pagination'], $filters),
            // What each day on the page cost, per unit — for the day headings.
            'dayTotals' => $page['dayTotals'],
        ]);
    }
}

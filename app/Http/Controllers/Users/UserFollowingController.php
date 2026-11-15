<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\BuildFollowingPanel;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Users\Concerns\SharesProfileHeader;
use App\Http\Requests\BellListRequest;
use App\Library\CreatorStateQuery;
use App\Library\LibraryStates;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The following panel of a user view — the one panel only its owner can
 * open, and the only one that reads follows instead of a media log.
 */
class UserFollowingController extends Controller
{
    use SharesProfileHeader;

    /** The bell's shortcut: the viewer's own panel. */
    public function mine(#[CurrentUser] User $viewer): RedirectResponse
    {
        return to_route('users.following', ['user' => $viewer->username]);
    }

    public function __invoke(
        #[CurrentUser] User $viewer,
        BellListRequest $request,
        User $user,
        BuildFollowingPanel $panel,
        LibraryStates $states,
        CreatorStateQuery $creatorStates,
    ): Response {
        Gate::authorize('viewFollowing', $user);

        $panelProps = $panel->execute($user, $request->filters(), $request->page());

        return Inertia::render('users/UserFollowing', [
            ...$this->profileHeader($viewer, $user),
            ...Arr::except($panelProps, 'titles'),
            'userStates' => $states->resolve($viewer, $panelProps['titles']),
            // The person / company cards' counterpart: the bell and the lists.
            'creatorStates' => $creatorStates->ofSummaries($viewer, array_column($panelProps['entries'], 'media')),
        ]);
    }
}

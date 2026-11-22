<?php

namespace App\Http\Controllers;

use App\Actions\Users\BuildFollowingPanel;
use App\Http\Requests\BellListRequest;
use App\Library\CreatorStateQuery;
use App\Library\LibraryStates;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The viewer's bells: every title, person, company and collection whose
 * releases they follow. Nobody else's business, so it is the viewer's own
 * page, not a panel of a profile.
 */
class FollowingController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        BellListRequest $request,
        BuildFollowingPanel $panel,
        LibraryStates $states,
        CreatorStateQuery $creatorStates,
    ): Response {
        $panelProps = $panel->execute($viewer, $request->filters(), $request->page());

        return Inertia::render('Following', [
            ...Arr::except($panelProps, 'titles'),
            'userStates' => $states->resolve($viewer, $panelProps['titles']),
            // The person / company cards' counterpart: the bell and the lists.
            'creatorStates' => $creatorStates->ofSummaries($viewer, array_column($panelProps['entries'], 'media')),
        ]);
    }
}

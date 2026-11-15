<?php

namespace App\Http\Controllers\Lists;

use App\Actions\Media\BuildListPage;
use App\Actions\Media\BuildListSummaries;
use App\Http\Controllers\Controller;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The list pages. Their writes go through API v1 (`ui.lists.*`), as every
 * write of the library does.
 */
class ListController extends Controller
{
    public function index(#[CurrentUser] User $viewer, BuildListSummaries $summaries): Response
    {
        return Inertia::render('lists/ListIndex', [
            'lists' => $summaries->execute($viewer, includePrivate: true),
            'owner' => $viewer->username,
        ]);
    }

    /** A user's list under its owner; the route binds only the owner's own. */
    public function show(#[CurrentUser] User $viewer, User $user, MediaList $list, BuildListPage $page): Response
    {
        // A private profile keeps its lists with it, public or not.
        Gate::authorize('view', $user);
        Gate::authorize('view', $list);

        return Inertia::render('lists/ListDetails', $page->execute($viewer, $list));
    }
}

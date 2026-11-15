<?php

namespace App\Http\Controllers\Users;

use App\Actions\Media\BuildListSummaries;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Users\Concerns\SharesProfileHeader;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserListsController extends Controller
{
    use SharesProfileHeader;

    public function __invoke(#[CurrentUser] User $viewer, User $user, BuildListSummaries $summaries): Response
    {
        Gate::authorize('view', $user);

        $isOwner = $viewer->id === $user->id;

        return Inertia::render('lists/ListIndex', [
            'lists' => $summaries->execute($user, includePrivate: $isOwner),
            'owner' => $user->username,
            // Reached as a panel of a user view, so it wears that view's
            // header; the own /lists page sends no profile and keeps none.
            ...$this->profileHeader($viewer, $user),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\BuildProgressPanel;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Users\Concerns\SharesProfileHeader;
use App\Http\Requests\Users\ProgressPanelRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "in progress" panel of a user view — BuildProgressPanel has its two
 * shelves.
 */
class UserProgressController extends Controller
{
    use SharesProfileHeader;

    public function __invoke(
        #[CurrentUser] User $viewer,
        ProgressPanelRequest $request,
        User $user,
        BuildProgressPanel $panel,
    ): Response {
        Gate::authorize('view', $user);

        return Inertia::render('users/UserProgress', [
            ...$this->profileHeader($viewer, $user),
            ...$panel->execute($viewer, $user, $request->progressFilters()),
        ]);
    }
}

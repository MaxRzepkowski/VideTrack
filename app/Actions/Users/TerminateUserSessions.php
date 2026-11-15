<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Signs the user out everywhere at once — when their account is blocked or
 * deleted. Only sessions kept in the database can be reached; with any other
 * driver the next request is turned away instead (a blocked account by
 * EnsureUserIsNotBlocked, a deleted one by having no account to load).
 */
final readonly class TerminateUserSessions
{
    public function execute(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config()->string('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }
    }
}

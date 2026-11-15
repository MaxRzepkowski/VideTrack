<?php

namespace App\Actions\Users;

use App\Integrations\Connections\Disconnect;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes an account and every trace of it — the one way an account goes,
 * from the settings Danger Zone and from `vide:user:delete` alike. The
 * library leaves on foreign-key cascades; what cannot is removed here: API
 * tokens and notifications (both polymorphic, with no constraint pointing
 * back), sessions, the avatar — a public file reachable by URL until it is
 * removed — and the grants of connected services, which live on at the
 * service until it is told.
 */
final readonly class DeleteAccount
{
    public function __construct(
        private TerminateUserSessions $terminateSessions,
        private Disconnect $disconnect,
    ) {}

    public function execute(User $user): void
    {
        // Outside the transaction: a revoke is a call to the service, and
        // the account is going either way.
        $user->serviceConnections()->get()->each(fn (ServiceConnection $connection) => $this->disconnect->execute($connection));

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->notifications()->delete();
            $this->terminateSessions->execute($user);
            $user->delete();
        });

        // Only once the account is gone: a failed delete keeps its avatar.
        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
        }
    }
}

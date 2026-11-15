<?php

namespace App\Console\Commands;

use App\Actions\Users\TerminateUserSessions;
use App\Console\Commands\Concerns\ResolvesUsers;
use Illuminate\Console\Command;

class UserBlockCommand extends Command
{
    use ResolvesUsers;

    protected $signature = 'vide:user:block
        {user : Username or e-mail}
        {--unblock : Lift the block instead}';

    protected $description = 'Block a user account (no login, sessions terminated) or unblock it with --unblock';

    public function handle(TerminateUserSessions $terminateSessions): int
    {
        $user = $this->resolveUser((string) $this->argument('user'));

        if ($user === null) {
            return self::FAILURE;
        }

        if ($this->option('unblock')) {
            $user->forceFill(['blocked_at' => null])->save();
            $this->info("User {$user->username} has been unblocked.");

            return self::SUCCESS;
        }

        $user->forceFill(['blocked_at' => now()])->save();

        $terminateSessions->execute($user);

        $this->info("User {$user->username} has been blocked.");

        return self::SUCCESS;
    }
}

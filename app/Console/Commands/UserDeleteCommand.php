<?php

namespace App\Console\Commands;

use App\Actions\Users\DeleteAccount;
use App\Console\Commands\Concerns\ResolvesUsers;
use Illuminate\Console\Command;

class UserDeleteCommand extends Command
{
    use ResolvesUsers;

    protected $signature = 'vide:user:delete
        {user : Username or e-mail}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete a user account with all their plays, ratings, favorites and lists';

    public function handle(DeleteAccount $deleteAccount): int
    {
        $user = $this->resolveUser((string) $this->argument('user'));

        if ($user === null) {
            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! $this->confirm("Delete {$user->username} <{$user->email}> and all their data? This cannot be undone.")) {
            $this->info('Aborted.');

            // Not a success: a script must tell "nothing done" from "deleted".
            return self::FAILURE;
        }

        $deleteAccount->execute($user);

        $this->info("User {$user->username} has been deleted.");

        return self::SUCCESS;
    }
}

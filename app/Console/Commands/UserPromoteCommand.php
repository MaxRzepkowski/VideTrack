<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesUsers;
use App\Enums\UserRole;
use Illuminate\Console\Command;

class UserPromoteCommand extends Command
{
    use ResolvesUsers;

    protected $signature = 'vide:user:promote
        {user : Username or e-mail}
        {--demote : Revoke the admin role instead}';

    protected $description = 'Grant a user the admin role, or revoke it with --demote';

    public function handle(): int
    {
        $user = $this->resolveUser((string) $this->argument('user'));

        if ($user === null) {
            return self::FAILURE;
        }

        if ($this->option('demote')) {
            if (! $user->isAdmin()) {
                $this->info("User {$user->username} is not an admin.");

                return self::SUCCESS;
            }

            $user->forceFill(['role' => UserRole::User])->save();
            $this->info("User {$user->username} is no longer an admin.");

            return self::SUCCESS;
        }

        if ($user->isAdmin()) {
            $this->info("User {$user->username} is already an admin.");

            return self::SUCCESS;
        }

        $user->forceFill(['role' => UserRole::Admin])->save();
        $this->info("User {$user->username} is now an admin.");

        return self::SUCCESS;
    }
}

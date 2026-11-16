<?php

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A new account with everything an account always has — its watchlist and
 * its calendar feed token — so reading either never has to create it. An
 * account made through an identity provider has no password.
 */
final readonly class CreateAccount
{
    public function __construct(private IssueCalendarToken $issueCalendarToken) {}

    public function execute(string $name, string $username, string $email, ?string $password, UserRole $role = UserRole::User): User
    {
        return DB::transaction(function () use ($name, $username, $email, $password, $role): User {
            $user = new User(['name' => $name, 'username' => $username, 'email' => $email, 'password' => $password]);
            $user->role = $role;
            $user->save();

            $user->lists()->create(['system_key' => MediaList::SYSTEM_WATCHLIST]);
            $this->issueCalendarToken->execute($user);

            return $user;
        });
    }
}

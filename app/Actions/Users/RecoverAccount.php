<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives a lost account a new password: the forgotten-password form and
 * `vide:user:reset-password` alike. Whoever held the account before keeps
 * nothing: the new hash ends every session (AuthenticateSession compares
 * it), a new remember token voids "remember me" cookies, and API tokens are
 * revoked, since one could have been minted by whoever took the account.
 * A password changed in the settings keeps the tokens: the list of them is
 * on the API page next to it.
 */
final readonly class RecoverAccount
{
    public function execute(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
        });
    }
}

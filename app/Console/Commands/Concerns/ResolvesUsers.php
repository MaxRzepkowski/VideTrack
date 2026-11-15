<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;

/**
 * Shared `{user}` argument resolution for the vide:user:* commands: the
 * identifier may be a username or an e-mail address.
 */
trait ResolvesUsers
{
    protected function resolveUser(string $identifier): ?User
    {
        $user = User::query()
            ->where('username', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        if ($user === null) {
            $this->error("No user with username or e-mail \"{$identifier}\".");
        }

        return $user;
    }
}

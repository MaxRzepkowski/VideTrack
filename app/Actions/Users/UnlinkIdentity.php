<?php

namespace App\Actions\Users;

use App\Accounts\Identity\IdentityProviderRegistry;
use App\Accounts\Identity\IdentityRefusal;
use App\Accounts\Identity\IdentityRefused;
use App\Accounts\PasswordLogin;
use App\Models\Identity;

/**
 * Stops an identity signing in to its account — never the last way in. A
 * password counts while passwords open the instance, another identity while
 * its provider is still configured.
 */
final readonly class UnlinkIdentity
{
    public function __construct(
        private PasswordLogin $passwordLogin,
        private IdentityProviderRegistry $providers,
    ) {}

    /** @throws IdentityRefused */
    public function execute(Identity $identity): void
    {
        $user = $identity->user;

        $anotherIdentity = $user->identities()
            ->whereKeyNot($identity->id)
            ->whereIn('provider', $this->providers->codes())
            ->exists();

        if (! $anotherIdentity && ! $this->passwordLogin->isWayInFor($user)) {
            throw new IdentityRefused(IdentityRefusal::LastSignInMethod);
        }

        $identity->delete();
    }
}

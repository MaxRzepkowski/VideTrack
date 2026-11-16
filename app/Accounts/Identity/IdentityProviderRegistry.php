<?php

namespace App\Accounts\Identity;

use App\Accounts\Identity\Contracts\IdentityProvider;
use App\Support\ServiceRegistry;

/**
 * The identity providers this instance offers: only the ones the operator
 * configured, in the order the sign-in page shows them.
 *
 * @extends ServiceRegistry<IdentityProvider>
 */
final class IdentityProviderRegistry extends ServiceRegistry
{
    protected function kind(): string
    {
        return 'identity provider';
    }
}

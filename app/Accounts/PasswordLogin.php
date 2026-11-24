<?php

namespace App\Accounts;

use App\Accounts\Identity\IdentityProviderRegistry;
use App\Models\User;

/**
 * Whether a password is a way into this instance. The operator may leave
 * identity providers as the only way in (PASSWORD_LOGIN_ENABLED=false), but
 * not with none configured: an instance nobody can enter is a missing
 * variable, not a choice, so the password stays (`app:ensure-admin` says so
 * on every start).
 */
final readonly class PasswordLogin
{
    public function __construct(private IdentityProviderRegistry $providers) {}

    public function isEnabled(): bool
    {
        return config()->boolean('tracker.auth.password_login') || ! $this->hasProviders();
    }

    /** Whether the operator asked for providers only and has none, so passwords stay. */
    public function isKeptForLackOfProviders(): bool
    {
        return ! config()->boolean('tracker.auth.password_login') && ! $this->hasProviders();
    }

    /**
     * Whether this user signs in with a password, and so confirms with it a
     * change that hands the account over or cannot be undone; without one,
     * they confirm with their username.
     */
    public function isWayInFor(User $user): bool
    {
        return $user->hasPassword() && $this->isEnabled();
    }

    private function hasProviders(): bool
    {
        return $this->providers->codes() !== [];
    }
}

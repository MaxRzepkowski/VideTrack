<?php

namespace App\Actions\Users;

use App\Accounts\Identity\Data\IdentityClaims;
use App\Accounts\Identity\IdentityRefusal;
use App\Accounts\Identity\IdentityRefused;
use App\Models\Identity;
use App\Models\User;

/**
 * Lets an account sign in with an identity at a provider. An identity
 * belongs to one account, and an account has one identity per provider.
 */
final readonly class LinkIdentity
{
    /** @throws IdentityRefused */
    public function execute(User $user, IdentityClaims $claims): Identity
    {
        $existing = Identity::query()
            ->where('provider', $claims->provider)
            ->where('subject', $claims->subject)
            ->first();

        if ($existing !== null) {
            return $existing->user_id === $user->id ? $existing : throw new IdentityRefused(IdentityRefusal::LinkedElsewhere);
        }

        if ($user->identities()->where('provider', $claims->provider)->exists()) {
            throw new IdentityRefused(IdentityRefusal::AlreadyConnected);
        }

        return $user->identities()->create([
            'provider' => $claims->provider,
            'subject' => $claims->subject,
            'email' => $claims->email,
        ]);
    }
}

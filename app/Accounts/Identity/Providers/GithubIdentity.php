<?php

namespace App\Accounts\Identity\Providers;

use App\Accounts\Identity\Data\IdentityClaims;
use App\Accounts\Identity\SocialiteIdentity;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * GitHub accounts. Socialite's driver keeps only the primary address GitHub
 * marks verified (scope user:email), so an address from it is a checked one
 * and its absence means there is none.
 */
final class GithubIdentity extends SocialiteIdentity
{
    public const CODE = 'github';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function glyph(): string
    {
        return 'github';
    }

    protected function driver(string $callbackUrl): AbstractProvider
    {
        return $this->build(
            GithubProvider::class,
            $callbackUrl,
            config()->string('tracker.auth.providers.github.client_id'),
            config()->string('tracker.auth.providers.github.client_secret'),
        );
    }

    protected function claims(SocialiteUser $user, string $subject): IdentityClaims
    {
        $email = $user->getEmail();

        return new IdentityClaims(
            provider: self::CODE,
            subject: $subject,
            email: $email,
            emailVerified: $email !== null,
            name: $user->getName(),
            username: $user->getNickname(),
        );
    }
}

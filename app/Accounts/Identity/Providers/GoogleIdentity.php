<?php

namespace App\Accounts\Identity\Providers;

use App\Accounts\Identity\Data\IdentityClaims;
use App\Accounts\Identity\SocialiteIdentity;
use App\Support\Payload;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/** Google accounts, which say whether Google checked the address. */
final class GoogleIdentity extends SocialiteIdentity
{
    public const CODE = 'google';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Google';
    }

    public function glyph(): string
    {
        return 'google';
    }

    protected function driver(string $callbackUrl): AbstractProvider
    {
        return $this->build(
            GoogleProvider::class,
            $callbackUrl,
            config()->string('tracker.auth.providers.google.client_id'),
            config()->string('tracker.auth.providers.google.client_secret'),
        );
    }

    protected function claims(SocialiteUser $user, string $subject): IdentityClaims
    {
        return new IdentityClaims(
            provider: self::CODE,
            subject: $subject,
            email: $user->getEmail(),
            emailVerified: Payload::bool(Payload::map($user->getRaw()), 'email_verified') === true,
            name: $user->getName(),
            username: null,
        );
    }
}

<?php

namespace App\Accounts\Identity\Providers;

use App\Accounts\Identity\Data\IdentityClaims;
use App\Accounts\Identity\Oidc\OidcClient;
use App\Accounts\Identity\SocialiteIdentity;
use App\Support\Payload;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * A provider that is an OpenID Connect server, found from its issuer. Its
 * standard claims say whether the address was checked; some servers send
 * that flag as the string "true".
 */
abstract class OpenIdConnectIdentity extends SocialiteIdentity
{
    abstract protected function issuer(): string;

    abstract protected function clientId(): string;

    abstract protected function clientSecret(): string;

    protected function driver(string $callbackUrl): AbstractProvider
    {
        return $this->build(OidcClient::class, $callbackUrl, $this->clientId(), $this->clientSecret())
            ->at($this->server->discover($this->issuer()));
    }

    protected function claims(SocialiteUser $user, string $subject): IdentityClaims
    {
        $raw = Payload::map($user->getRaw());

        return new IdentityClaims(
            provider: $this->code(),
            subject: $subject,
            email: $user->getEmail(),
            emailVerified: in_array($raw['email_verified'] ?? null, [true, 'true'], true),
            name: $user->getName(),
            username: $user->getNickname(),
        );
    }
}

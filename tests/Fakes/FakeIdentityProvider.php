<?php

namespace Tests\Fakes;

use App\Accounts\Identity\Contracts\IdentityProvider;
use App\Accounts\Identity\Data\IdentityClaims;
use App\Support\ExternalServiceException;
use LogicException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * An identity provider whose answer the test sets: who comes back, or the
 * failure the trip ends in. It remembers where it was told to send people
 * back.
 */
class FakeIdentityProvider implements IdentityProvider
{
    public const CODE = 'fake-identity';

    public static ?IdentityClaims $comesBack = null;

    public static ?ExternalServiceException $fails = null;

    public static ?string $callbackUrl = null;

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Fake ID';
    }

    public function glyph(): string
    {
        return 'openid';
    }

    public function redirect(string $callbackUrl): RedirectResponse
    {
        self::$callbackUrl = $callbackUrl;

        if (self::$fails !== null) {
            throw self::$fails;
        }

        return new RedirectResponse('https://id.example.test/authorize');
    }

    public function identify(string $callbackUrl): IdentityClaims
    {
        self::$callbackUrl = $callbackUrl;

        if (self::$fails !== null) {
            throw self::$fails;
        }

        return self::$comesBack ?? throw new LogicException('The test did not say who comes back.');
    }
}

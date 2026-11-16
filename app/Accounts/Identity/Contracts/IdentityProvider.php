<?php

namespace App\Accounts\Identity\Contracts;

use App\Accounts\Identity\Data\IdentityClaims;
use App\Accounts\Identity\IdentityRefused;
use App\Support\ServiceRejected;
use App\Support\ServiceUnavailable;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * A service people sign in with: the instance sends them there and reads
 * who came back. Configured by the operator, never by a user.
 */
interface IdentityProvider
{
    public function code(): string;

    /** The provider's name on the sign-in button and in settings. */
    public function label(): string;

    /** The brand mark the page draws, a key of `lib/serviceGlyphs.ts`. */
    public function glyph(): string;

    /** The way to the provider, which sends the person back to $callbackUrl. */
    public function redirect(string $callbackUrl): RedirectResponse;

    /**
     * Who the provider says came back to $callbackUrl, read from the request
     * it sent them back with.
     *
     * @throws IdentityRefused|ServiceRejected|ServiceUnavailable
     */
    public function identify(string $callbackUrl): IdentityClaims;
}

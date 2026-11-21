<?php

namespace Tests\Fakes;

use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Contracts\AuthorizesByRedirect;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\Data\RedirectAuthorization;

/**
 * A service connected by a trip to its site: it sends the user to
 * example.test with the attempt's state and keeps a verifier, which it
 * needs back to turn the code into a grant.
 */
class FakeRedirectService implements AuthorizesByRedirect
{
    public const CODE = 'fake-redirect';

    /** @var list<string> the callback addresses it was told, in order */
    public static array $callbacks = [];

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Fake Redirect';
    }

    public function beginRedirect(string $callbackUrl, string $state): RedirectAuthorization
    {
        self::$callbacks[] = $callbackUrl;

        return new RedirectAuthorization('https://example.test/authorize?state='.$state, ['verifier' => 'verifier-of-alex']);
    }

    public function completeRedirect(array $answer, array $kept, string $callbackUrl): ConnectionGrant
    {
        self::$callbacks[] = $callbackUrl;

        if (! isset($answer['code']) || $kept['verifier'] !== 'verifier-of-alex') {
            throw new ConnectionRejected('import.the_connection_attempt_expired');
        }

        return new ConnectionGrant(['access_token' => "token-for-{$answer['code']}"], 'alex');
    }
}

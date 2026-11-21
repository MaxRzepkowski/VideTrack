<?php

namespace App\Integrations\MangaBaka;

use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Contracts\AuthorizesByRedirect;
use App\Integrations\Connections\Contracts\RevokesAccess;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\Data\RedirectAuthorization;
use App\Models\ServiceConnection;
use Illuminate\Support\Str;

/**
 * Connecting a MangaBaka account through OAuth: the user approves the
 * operator's app on mangabaka.org, which sends them back with a code; the
 * code and the PKCE verifier kept for the attempt make the grant.
 * MangaBaka asks apps acting for other people to use this, never a pasted
 * personal token.
 */
class MangaBakaService implements AuthorizesByRedirect, RevokesAccess
{
    public const CODE = 'mangabaka';

    public const NAME = 'MangaBaka';

    /** RFC 7636: a verifier of 43 to 128 unreserved characters. */
    private const VERIFIER_LENGTH = 64;

    public function __construct(private readonly MangaBakaClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function beginRedirect(string $callbackUrl, string $state): RedirectAuthorization
    {
        $verifier = Str::random(self::VERIFIER_LENGTH);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return new RedirectAuthorization($this->client->authorizationUrl($callbackUrl, $state, $challenge), ['verifier' => $verifier]);
    }

    /**
     * MangaBaka sends an `error` instead of a code when the user said no.
     */
    public function completeRedirect(array $answer, array $kept, string $callbackUrl): ConnectionGrant
    {
        $verifier = $kept['verifier'] ?? throw new ConnectionRejected('import.the_connection_attempt_expired');
        $code = $answer['code'] ?? null;

        if (isset($answer['error']) || $code === null) {
            throw new ConnectionRejected('import.mangabaka_connection_was_not_approved');
        }

        $tokens = $this->client->exchange($code, $verifier, $callbackUrl);

        return new ConnectionGrant($tokens, $this->client->username($tokens['access_token']));
    }

    public function revoke(ServiceConnection $connection): void
    {
        $this->client->revoke($connection);
    }
}

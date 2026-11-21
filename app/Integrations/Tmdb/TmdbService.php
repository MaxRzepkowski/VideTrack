<?php

namespace App\Integrations\Tmdb;

use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Contracts\AuthorizesByRedirect;
use App\Integrations\Connections\Contracts\RevokesAccess;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\Data\RedirectAuthorization;
use App\Models\ServiceConnection;

/**
 * Connecting a TMDB account the way API v3 signs a user in: a request token
 * the user approves on themoviedb.org, which sends them back here, then a
 * session made from it. TMDB takes any return address, so the instance
 * needs nothing registered beyond its key.
 */
class TmdbService implements AuthorizesByRedirect, RevokesAccess
{
    public const CODE = 'tmdb';

    public const NAME = 'TMDB';

    private const APPROVAL_URL = 'https://www.themoviedb.org/authenticate/';

    public function __construct(private readonly TmdbAccountClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * TMDB has no state of its own: the attempt's rides in the return
     * address's path, which TMDB keeps whatever query it adds.
     */
    public function beginRedirect(string $callbackUrl, string $state): RedirectAuthorization
    {
        $token = $this->client->requestToken();
        $back = $callbackUrl.'/'.rawurlencode($state);

        return new RedirectAuthorization(
            self::APPROVAL_URL.rawurlencode($token).'?'.http_build_query(['redirect_to' => $back]),
            ['request_token' => $token],
        );
    }

    /**
     * The token this attempt kept is the one turned into a session, never
     * one the way back names; a token the user did not approve makes none.
     */
    public function completeRedirect(array $answer, array $kept, string $callbackUrl): ConnectionGrant
    {
        $token = $kept['request_token'] ?? throw new ConnectionRejected('import.the_connection_attempt_expired');
        $session = $this->client->createSession($token);
        $account = $this->client->account($session);

        return new ConnectionGrant(['session_id' => $session], $account['username'], ['account_id' => $account['id']]);
    }

    public function revoke(ServiceConnection $connection): void
    {
        $this->client->deleteSession($connection);
    }
}

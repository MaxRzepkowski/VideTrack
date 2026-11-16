<?php

namespace App\Accounts\Identity;

use App\Accounts\Identity\Data\OidcEndpoints;
use App\Support\HttpRetry;
use App\Support\Payload;
use App\Support\ServiceRejected;
use App\Support\ServiceUnavailable;
use GuzzleHttp\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The instance's requests to identity servers. Socialite sends the sign-in
 * steps through the Guzzle client from here, so they carry the instance's
 * User-Agent and timeout and tests fake them like any other request; the
 * OpenID Connect discovery document is read here too. Only discovery is
 * tried again after a dropped connection or a 5xx; a sign-in step is not,
 * since its code is good for one use and the person asks again with a click.
 */
final class IdentityServerClient
{
    private const TIMEOUT_SECONDS = 15;

    public function guzzle(): Client
    {
        return new Client([
            // Laravel's own handler stack, so Http::fake and the stray-request
            // guard see these requests.
            'handler' => Http::buildHandlerStack(),
            'headers' => ['User-Agent' => config()->string('tracker.http.user_agent')],
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::TIMEOUT_SECONDS,
        ]);
    }

    /**
     * The endpoints of the server at $issuer. The document has to name that
     * same issuer: a server answering for another one could hand out
     * somebody else's endpoints.
     *
     * @throws ServiceRejected|ServiceUnavailable
     */
    public function discover(string $issuer): OidcEndpoints
    {
        $issuer = rtrim($issuer, '/');

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->withUserAgent(config()->string('tracker.http.user_agent'))
                ->retry(2, 500, HttpRetry::isTransient(...), throw: false)
                ->get("{$issuer}/.well-known/openid-configuration");
        } catch (ConnectionException $e) {
            throw new ServiceUnavailable('account.identity_server_unreachable', previous: $e);
        }

        if (HttpRetry::isOutage($response)) {
            throw new ServiceUnavailable('account.identity_server_unreachable', ['status' => $response->status()]);
        }

        $document = $response->successful() ? Payload::map($response->json()) : [];
        $named = Payload::string($document, 'issuer');
        $authorization = Payload::string($document, 'authorization_endpoint');
        $token = Payload::string($document, 'token_endpoint');
        $userinfo = Payload::string($document, 'userinfo_endpoint');

        if ($named === null || rtrim($named, '/') !== $issuer || $authorization === null || $token === null || $userinfo === null) {
            throw new ServiceRejected('account.identity_server_misconfigured', ['status' => $response->status()]);
        }

        return new OidcEndpoints($authorization, $token, $userinfo);
    }
}

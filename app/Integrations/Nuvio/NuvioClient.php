<?php

namespace App\Integrations\Nuvio;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Data\TokenGrant;
use App\Integrations\Connections\GrantUnavailable;
use App\Integrations\Connections\OwnServerClient;
use App\Integrations\Connections\OwnServerUnavailable;
use App\Integrations\Connections\TokenGrantClient;
use App\Integrations\Connections\TokenRequestStyle;
use App\Integrations\Connections\UnusableAddress;
use App\Integrations\Connections\UserAddresses;
use App\Integrations\ServiceHttpClient;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Support\HttpRetry;
use App\Support\Payload;
use App\Support\ServiceBusy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use LogicException;

/**
 * Nuvio's documented public API (nuvio.tv/docs/nuvio-public-api.md): Supabase
 * Auth under `/auth/v1` and RPC functions under `/rest/v1/rpc`, every request
 * with the project's publishable key in `apikey`, signed in by e-mail and
 * password to an hour-long session whose refresh token is spent on use.
 * Nuvio's cloud, or a server the user runs (NuvioMedia/self-host), which
 * says its own key at `/.well-known/nuvio`. Up to 100 requests a second per
 * account. A run waits out a dropped connection, a 5xx or a 429; nothing
 * else answered is asked again, and nothing while connecting is.
 *
 * A run's request is not `OwnServerClient::read()`: its 401 has to reach
 * TokenGrantClient, which refreshes the session, and an RPC may answer a
 * bare number.
 */
class NuvioClient
{
    public const NAME = 'Nuvio';

    public const CLOUD_URL = 'https://api.nuvio.tv';

    /** Printed in Nuvio's public API documentation for every outside client to use. */
    public const CLOUD_KEY = 'sb_publishable_1Clq8rlTVACkdcZuqr6_AD__xUUC_EN';

    /** The documented page sizes: the history 500 at a time, the change feed at most 1000. */
    public const HISTORY_PAGE = 500;

    public const CHANGES_PAGE = 1000;

    /** Ends this instance's session alone: the default scope would sign the account out of every app. */
    private const SIGN_OUT_PATH = '/auth/v1/logout?scope=local';

    /** A session holds an hour; refreshed in its last minutes rather than on a 401. */
    public const REFRESH_MARGIN_SECONDS = 300;

    /** What the discovery document may weigh: a few keys, nothing more. */
    private const DISCOVERY_MAX_BYTES = 16384;

    public function __construct(
        private readonly UserAddresses $addresses,
        private readonly OwnServerClient $server,
        private readonly ServiceHttpClient $http,
        private readonly TokenGrantClient $tokens,
    ) {}

    /**
     * The server the form names: Nuvio's cloud when the address is left
     * empty, else the address typed, checked for the owner, with the key it
     * announces. The address the document gives is not taken: on a server
     * run on one machine it reads localhost.
     *
     * @throws ConnectionRejected|UnusableAddress
     */
    public function server(string $typed, User $owner): NuvioServer
    {
        if (trim($typed) === '') {
            return new NuvioServer(self::CLOUD_URL, self::CLOUD_KEY, $this->addresses->pin(self::CLOUD_URL, $owner));
        }

        $address = $this->server->pinTyped($typed, '/(rest|auth)/v1', $owner);
        $document = $this->server->check(
            $this->server->to($address, '')->withOptions(['curl' => [CURLOPT_MAXFILESIZE => self::DISCOVERY_MAX_BYTES]]),
            self::NAME,
            '/.well-known/nuvio',
            refusals: [404 => 'import.there_is_no_nuvio_server_at_this_address'],
        );
        $key = Payload::string($document, 'publishable_key');

        if (Payload::string($document, 'service') !== 'nuvio' || $key === null) {
            throw new ConnectionRejected('import.there_is_no_nuvio_server_at_this_address');
        }

        return new NuvioServer($address->url, $key, $address);
    }

    /**
     * Signs in with what the user typed, once: a refused e-mail or password
     * is final, never asked again.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int}
     *
     * @throws ConnectionRejected
     */
    public function signIn(NuvioServer $server, string $email, string $password): array
    {
        $grant = $this->grant($server);

        try {
            $response = $this->tokens->post($grant, '/auth/v1/token?grant_type=password', ['email' => $email, 'password' => $password]);
        } catch (GrantUnavailable) {
            throw new ConnectionRejected('import.service_could_not_be_reached_at', ['service' => self::NAME]);
        }

        if (in_array($response->status(), [400, 401, 422], true)) {
            throw new ConnectionRejected('import.nuvio_did_not_accept_this_email_and_password');
        }

        if (! $response->successful()) {
            throw new ConnectionRejected('import.service_answered_try_later', ['service' => self::NAME, 'status' => $response->status()]);
        }

        try {
            return $this->tokens->readTokens($grant, $response, null);
        } catch (GrantUnavailable) {
            throw new ConnectionRejected('import.service_could_not_be_read_at', ['service' => self::NAME]);
        }
    }

    /**
     * The account's profiles, by their index and name, with a fresh session.
     *
     * @return list<array{index: int, name: string}>
     *
     * @throws ConnectionRejected
     */
    public function profiles(NuvioServer $server, string $accessToken): array
    {
        $answer = $this->firstCall($server, $accessToken, 'sync_pull_profiles', null);
        $profiles = [];

        foreach (Payload::list($answer) as $profile) {
            $index = Payload::int($profile, 'profile_index');

            if ($index !== null) {
                $profiles[] = ['index' => $index, 'name' => Payload::string($profile, 'name') ?? (string) $index];
            }
        }

        return $profiles;
    }

    /**
     * Where the profile's change feed stands now, read while connecting.
     *
     * @throws ConnectionRejected
     */
    public function cursorNow(NuvioServer $server, string $accessToken, int $profile): int
    {
        $cursor = $this->firstCall($server, $accessToken, 'sync_get_watched_items_delta_cursor', ['p_profile_id' => $profile]);

        return is_int($cursor) ? $cursor : throw new ConnectionRejected('import.service_could_not_be_read_at', ['service' => self::NAME]);
    }

    /**
     * Where the profile's change feed stands now, for a run.
     *
     * @throws ConnectionExpired|GrantUnavailable|OwnServerUnavailable|NuvioUnavailable|NuvioRejected|ServiceBusy
     */
    public function cursor(ServiceConnection $connection): int
    {
        $cursor = $this->rpc($connection, 'sync_get_watched_items_delta_cursor', ['p_profile_id' => $this->profile($connection)]);

        // Read as nought, it would bring in the whole history the user did not ask for.
        return is_int($cursor) ? $cursor : throw new NuvioRejected('import.service_could_not_be_read_at', ['service' => self::NAME]);
    }

    /**
     * One page of the profile's history as it stands, newest first.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws ConnectionExpired|GrantUnavailable|OwnServerUnavailable|NuvioUnavailable|NuvioRejected|ServiceBusy
     */
    public function history(ServiceConnection $connection, int $page): array
    {
        return Payload::list($this->rpc($connection, 'sync_pull_watched_items', [
            'p_profile_id' => $this->profile($connection),
            'p_page' => $page,
            'p_page_size' => self::HISTORY_PAGE,
        ]));
    }

    /**
     * The history's changes after the event $since, oldest first.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws ConnectionExpired|GrantUnavailable|OwnServerUnavailable|NuvioUnavailable|NuvioRejected|ServiceBusy
     */
    public function changes(ServiceConnection $connection, int $since): array
    {
        return Payload::list($this->rpc($connection, 'sync_pull_watched_items_delta', [
            'p_profile_id' => $this->profile($connection),
            'p_since_event_id' => $since,
            'p_limit' => self::CHANGES_PAGE,
        ]));
    }

    /**
     * Ends a session made only to look (the profiles to pick from) or left
     * by a connect that did not finish, this one alone. Nothing depends on
     * it: one that cannot be ended is only a stale session in the account.
     */
    public function endSession(NuvioServer $server, string $accessToken): void
    {
        try {
            $this->request($server, $accessToken)->post(self::SIGN_OUT_PATH);
        } catch (ConnectionException) {
            // Left to lapse.
        }
    }

    /**
     * Ends the connection's session, and only it ({@see SIGN_OUT_PATH}).
     *
     * @throws ConnectionExpired|GrantUnavailable|OwnServerUnavailable
     */
    public function signOut(ServiceConnection $connection): void
    {
        $this->tokens->revoke($this->grant($this->stored($connection)), $connection);
    }

    /** How the server's sessions are refreshed and ended: Supabase's way, held to the approved address. */
    private function grant(NuvioServer $server): TokenGrant
    {
        return new TokenGrant(
            service: self::NAME,
            baseUrl: $server->url,
            clientId: $server->key,
            clientSecret: null,
            tokenPath: '/auth/v1/token?grant_type=refresh_token',
            revokePath: self::SIGN_OUT_PATH,
            expiredKey: 'import.nuvio_stopped_accepting_this_connection',
            style: TokenRequestStyle::SupabaseJson,
            refreshMarginSeconds: self::REFRESH_MARGIN_SECONDS,
            pinned: $server->address,
        );
    }

    /**
     * The connection's server, its address checked again for this run.
     *
     * @throws ConnectionExpired|OwnServerUnavailable
     */
    private function stored(ServiceConnection $connection): NuvioServer
    {
        return new NuvioServer(
            (string) $connection->credential('url'),
            $connection->credential('key') ?? self::CLOUD_KEY,
            $this->server->address($connection, self::NAME),
        );
    }

    /** The profile the user picked while connecting; a connection without one was never made by this form. */
    private function profile(ServiceConnection $connection): int
    {
        $profile = $connection->setting('profile');

        return $profile !== null && ctype_digit($profile) ? (int) $profile : throw new LogicException('A Nuvio connection without its profile.');
    }

    /**
     * One RPC of a run, with the connection's session kept fresh.
     *
     * @param  array<string, int>|null  $body
     *
     * @throws ConnectionExpired|GrantUnavailable|OwnServerUnavailable|NuvioUnavailable|NuvioRejected|ServiceBusy
     */
    private function rpc(ServiceConnection $connection, string $function, ?array $body): mixed
    {
        $server = $this->stored($connection);
        $response = $this->tokens->call($this->grant($server), $connection, fn (string $token): Response => $this->http->unlessBusy(
            self::NAME,
            $this->http->reach(self::NAME, NuvioUnavailable::class, fn (): Response => $this->send(
                $this->http->retrying($this->request($server, $token), OwnServerClient::TRIES, OwnServerClient::FIRST_RETRY_MS, HttpRetry::isTransientOrThrottled(...)),
                $function,
                $body,
            )),
            OwnServerClient::DEFAULT_WAIT_SECONDS,
        ));

        if (HttpRetry::isOutage($response)) {
            throw new NuvioUnavailable('import.service_answered_to', ['service' => self::NAME, 'status' => $response->status(), 'path' => $function]);
        }

        // Answered and refused: the same request would be refused again.
        if (! $response->successful()) {
            throw new NuvioRejected('import.service_answered_to', ['service' => self::NAME, 'status' => $response->status(), 'path' => $function]);
        }

        return $response->json();
    }

    /**
     * One RPC while connecting, with the session just made; never retried.
     *
     * @param  array<string, int>|null  $body
     *
     * @throws ConnectionRejected
     */
    private function firstCall(NuvioServer $server, string $accessToken, string $function, ?array $body): mixed
    {
        try {
            $response = $this->send($this->request($server, $accessToken), $function, $body);
        } catch (ConnectionException) {
            throw new ConnectionRejected('import.service_could_not_be_reached_at', ['service' => self::NAME]);
        }

        if (! $response->successful()) {
            throw new ConnectionRejected('import.service_answered_try_later', ['service' => self::NAME, 'status' => $response->status()]);
        }

        return $response->json();
    }

    /** @param  array<string, int>|null  $body */
    private function send(PendingRequest $request, string $function, ?array $body): Response
    {
        return $body === null ? $request->post("/rest/v1/rpc/{$function}") : $request->asJson()->post("/rest/v1/rpc/{$function}", $body);
    }

    private function request(NuvioServer $server, string $accessToken): PendingRequest
    {
        return $server->address->hold($this->http->request($server->url))
            ->withHeaders(['apikey' => $server->key])
            ->withToken($accessToken);
    }
}

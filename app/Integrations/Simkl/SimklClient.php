<?php

namespace App\Integrations\Simkl;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\Data\DeviceGrant;
use App\Integrations\Connections\GrantUnavailable;
use App\Integrations\Connections\TokenGrantClient;
use App\Integrations\ServiceHttpClient;
use App\Models\ServiceConnection;
use App\Support\HttpRetry;
use App\Support\Payload;
use App\Support\ServiceBusy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use LogicException;

/**
 * Simkl's API as its rules ask to be called (api.simkl.org, read 2026-09-27):
 *
 * - every API call carries the user's own app's client_id (kept with the
 *   connection's tokens), app-name and app-version in the query
 *   and a User-Agent naming the app; the OAuth endpoints take a form body;
 * - calls go one after another (10 GET/s per client id and token);
 * - 429 and 5xx are waited out — doubling from one second, five attempts —
 *   and nothing else is retried: an answered 4xx is final;
 * - AUTH V2 access tokens live seven days and one grant holds exactly one
 *   live token, so a refresh by anyone cuts off every copy — what
 *   TokenGrantClient's refresh guards against.
 */
class SimklClient
{
    /** Where the connection keeps the id of the user's Simkl app. */
    public const CLIENT_ID = 'client_id';

    /** Where a user makes their Simkl app. */
    public const DEVELOPER_URL = 'https://simkl.com/settings/developer/';

    private const TIMEOUT_SECONDS = 60;

    public const TRIES = 5;

    private const FIRST_RETRY_MS = 1000;

    /** A 429 still standing after the tries waits a minute, unless Simkl names a wait. */
    private const DEFAULT_WAIT_SECONDS = 60;

    public function __construct(private readonly TokenGrantClient $tokens, private readonly ServiceHttpClient $http) {}

    /**
     * The account name, for the card — read with a token straight from the
     * device flow, before any connection row exists. Only a label: a dropped
     * connection here leaves it unknown rather than losing the grant the
     * user just approved.
     */
    public function accountName(string $accessToken, string $clientId): ?string
    {
        try {
            $response = $this->api($accessToken)->get('/users/settings', $this->appQuery($clientId));
        } catch (ConnectionException) {
            return null;
        }

        $name = $response->successful() ? $response->json('user.name') : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<mixed>
     *
     * @throws SimklUnavailable|SimklRejected|ServiceBusy|ConnectionExpired|GrantUnavailable
     */
    public function get(ServiceConnection $connection, string $path, array $query = []): array
    {
        $clientId = $connection->credential(self::CLIENT_ID) ?? throw new LogicException('A Simkl connection without the app it was made with.');
        $response = $this->http->unlessBusy(
            SimklService::NAME,
            $this->tokens->call($this->grant($clientId)->tokenGrant(), $connection, fn (string $token): Response => $this->send($token, $clientId, $path, $query)),
            self::DEFAULT_WAIT_SECONDS,
        );

        if (HttpRetry::isOutage($response)) {
            throw new SimklUnavailable('import.service_answered_try_later', ['service' => SimklService::NAME, 'status' => $response->status()]);
        }

        // Answered and refused: the same request would be refused again.
        if (! $response->successful()) {
            throw new SimklRejected('import.service_answered_to', ['service' => SimklService::NAME, 'status' => $response->status(), 'path' => $path]);
        }

        return Payload::map($response->json());
    }

    /**
     * @param  array<string, string>  $query
     */
    private function send(string $token, string $clientId, string $path, array $query): Response
    {
        $request = $this->http->retrying($this->api($token), self::TRIES, self::FIRST_RETRY_MS, HttpRetry::isTransientOrThrottled(...));

        return $this->http->reach(SimklService::NAME, SimklUnavailable::class, fn (): Response => $request->get($path, [...$query, ...$this->appQuery($clientId)]));
    }

    /** Where the user's app keeps its device grant, for connecting and for every call made with it. */
    public function grant(string $clientId): DeviceGrant
    {
        return new DeviceGrant(
            service: SimklService::NAME,
            baseUrl: config()->string('tracker.simkl.base_url'),
            clientId: $clientId,
            scope: 'media:read',
            devicePath: '/oauth2/device',
            tokenPath: '/oauth2/token',
            revokePath: '/oauth2/revoke',
            rejectedKey: 'import.simkl_did_not_accept_this_client_id',
            notStartedKey: 'import.simkl_could_not_start_the_connection',
            expiredKey: 'import.simkl_stopped_accepting_this_connection_connect',
            verificationUri: 'https://simkl.com/pin',
        );
    }

    private function api(string $token): PendingRequest
    {
        return $this->http->request(config()->string('tracker.simkl.base_url'), self::TIMEOUT_SECONDS)->withToken($token);
    }

    /**
     * @return array{client_id: string, app-name: string, app-version: string}
     */
    private function appQuery(string $clientId): array
    {
        return [
            'client_id' => $clientId,
            'app-name' => 'videtrack',
            'app-version' => config()->string('tracker.version'),
        ];
    }
}

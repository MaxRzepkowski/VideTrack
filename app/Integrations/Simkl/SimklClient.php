<?php

namespace App\Integrations\Simkl;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\Data\DeviceGrant;
use App\Integrations\Connections\GrantUnavailable;
use App\Integrations\Connections\TokenGrantClient;
use App\Models\ServiceConnection;
use App\Support\ConnectionFailure;
use App\Support\HttpRetry;
use App\Support\Payload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Simkl's API as its rules ask to be called (api.simkl.org, read 2026-09-27):
 *
 * - every API call carries client_id, app-name and app-version in the query
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
    private const TIMEOUT_SECONDS = 60;

    public function __construct(private readonly TokenGrantClient $tokens) {}

    /**
     * The account name, for the card — read with a token straight from the
     * device flow, before any connection row exists. Only a label: a dropped
     * connection here leaves it unknown rather than losing the grant the
     * user just approved.
     */
    public function accountName(string $accessToken): ?string
    {
        try {
            $response = $this->api($accessToken)->get('/users/settings', $this->appQuery());
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
     * @throws SimklUnavailable|SimklRejected|ConnectionExpired|GrantUnavailable
     */
    public function get(ServiceConnection $connection, string $path, array $query = []): array
    {
        $response = $this->tokens->call($this->grant()->tokenGrant(), $connection, fn (string $token): Response => $this->send($token, $path, $query));

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
    private function send(string $token, string $path, array $query): Response
    {
        try {
            return $this->api($token)
                ->retry(5, fn (int $attempt): int => min(60000, 1000 * 2 ** ($attempt - 1)), HttpRetry::isTransientOrThrottled(...), throw: false)
                ->get($path, [...$query, ...$this->appQuery()]);
        } catch (ConnectionException $e) {
            throw new SimklUnavailable('import.service_unreachable', ['service' => SimklService::NAME, 'reason' => ConnectionFailure::reason($e)]);
        }
    }

    /** Where the service keeps its device grant, for connecting and for every call made with it. */
    public function grant(): DeviceGrant
    {
        return new DeviceGrant(
            service: SimklService::NAME,
            baseUrl: config()->string('tracker.simkl.base_url'),
            clientId: $this->clientId(),
            scope: 'media:read',
            devicePath: '/oauth2/device',
            tokenPath: '/oauth2/token',
            revokePath: '/oauth2/revoke',
            rejectedKey: 'import.simkl_did_not_accept_this_instance',
            notStartedKey: 'import.simkl_could_not_start_the_connection',
            expiredKey: 'import.simkl_stopped_accepting_this_connection_connect',
            verificationUri: 'https://simkl.com/pin',
        );
    }

    private function api(string $token): PendingRequest
    {
        return Http::baseUrl(rtrim(config()->string('tracker.simkl.base_url'), '/'))
            ->timeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->withUserAgent(config()->string('tracker.http.user_agent'))
            ->withToken($token);
    }

    /**
     * @return array{client_id: string, app-name: string, app-version: string}
     */
    private function appQuery(): array
    {
        return [
            'client_id' => $this->clientId(),
            'app-name' => 'videtrack',
            'app-version' => config()->string('tracker.version'),
        ];
    }

    private function clientId(): string
    {
        return config()->string('tracker.simkl.client_id');
    }
}

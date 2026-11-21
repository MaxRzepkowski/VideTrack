<?php

namespace App\Integrations\MdbList;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\Data\DeviceGrant;
use App\Integrations\Connections\GrantUnavailable;
use App\Integrations\Connections\TokenGrantClient;
use App\Models\ServiceConnection;
use App\Support\ConnectionFailure;
use App\Support\HttpRetry;
use App\Support\Payload;
use App\Support\ServiceBusy;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * MDBList's API as its reference describes it (api.mdblist.com/docs,
 * schema read 2026-11-21):
 *
 * - a user connects through the Device Code app the operator registered;
 *   MDBList has one scope, `write`, though nothing here writes;
 * - access tokens live 30 days and are refreshed with the refresh token,
 *   through TokenGrantClient;
 * - limits are per account: 1,000 requests a day on a free account and
 *   1,000 reads every five minutes. Past either, MDBList answers 429 and
 *   asks to wait its Retry-After rather than retry in a loop: the run is put
 *   back on the queue for that long, or ends when the wait is a day's. Only
 *   a dropped connection or a 5xx is asked again at once;
 * - list endpoints page with a cursor, up to 1,000 items a page.
 */
class MdbListClient
{
    public const PAGE_SIZE = 1000;

    private const TIMEOUT_SECONDS = 60;

    private const RETRIES = 3;

    private const FIRST_RETRY_MS = 1000;

    /** MDBList's windows are five minutes long: a 429 without a Retry-After waits one out. */
    private const DEFAULT_WAIT_SECONDS = 300;

    public function __construct(private readonly TokenGrantClient $tokens) {}

    /**
     * The username, for the card — read with a token straight from the
     * device flow, before any connection row exists. Only a label.
     */
    public function username(string $accessToken): ?string
    {
        try {
            $response = $this->http($accessToken)->get('/user');
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? Payload::string(Payload::map($response->json()), 'username') : null;
    }

    /**
     * Every page of a paged endpoint, one answer at a time, following
     * `pagination.next_cursor` until none comes back.
     *
     * @param  array<string, string>  $query
     * @return Generator<int, array<array-key, mixed>>
     *
     * @throws MdbListUnavailable|MdbListRejected|ServiceBusy|ConnectionExpired|GrantUnavailable
     */
    public function pages(ServiceConnection $connection, string $path, array $query = []): Generator
    {
        $cursor = null;

        do {
            $page = Payload::map($this->get($connection, $path, [...$query, 'limit' => (string) self::PAGE_SIZE, ...($cursor === null ? [] : ['cursor' => $cursor])]));

            yield $page;

            $cursor = Payload::string(Payload::map($page['pagination'] ?? null), 'next_cursor');
        } while ($cursor !== null);
    }

    /**
     * @param  array<string, string>  $query
     *
     * @throws MdbListUnavailable|MdbListRejected|ServiceBusy|ConnectionExpired|GrantUnavailable
     */
    public function get(ServiceConnection $connection, string $path, array $query = []): mixed
    {
        $response = $this->tokens->call($this->grant()->tokenGrant(), $connection, fn (string $token): Response => $this->send($token, $path, $query));

        if ($response->status() === 429) {
            throw new ServiceBusy('import.service_asked_to_wait', HttpRetry::retryAfter($response, self::DEFAULT_WAIT_SECONDS), ['service' => MdbListService::NAME]);
        }

        if (HttpRetry::isOutage($response)) {
            throw new MdbListUnavailable('import.service_answered_try_later', ['service' => MdbListService::NAME, 'status' => $response->status()]);
        }

        // Answered and refused: the same request would be refused again.
        if (! $response->successful()) {
            throw new MdbListRejected('import.service_answered_to', ['service' => MdbListService::NAME, 'status' => $response->status(), 'path' => $path]);
        }

        return $response->json();
    }

    /**
     * @param  array<string, string>  $query
     */
    private function send(string $token, string $path, array $query): Response
    {
        try {
            return $this->http($token)
                ->retry(self::RETRIES, fn (int $attempt): int => self::FIRST_RETRY_MS * 2 ** ($attempt - 1), HttpRetry::isTransient(...), throw: false)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new MdbListUnavailable('import.service_unreachable', ['service' => MdbListService::NAME, 'reason' => ConnectionFailure::reason($e)]);
        }
    }

    /** Where the service keeps its device grant, for connecting and for every call made with it. */
    public function grant(): DeviceGrant
    {
        return new DeviceGrant(
            service: MdbListService::NAME,
            baseUrl: $this->baseUrl(),
            clientId: config()->string('tracker.mdblist.client_id'),
            scope: 'write',
            devicePath: '/oauth/device-authorization/',
            tokenPath: '/oauth/token/',
            revokePath: '/oauth/revoke_token/',
            rejectedKey: 'import.mdblist_did_not_accept_this_instance',
            notStartedKey: 'import.the_service_could_not_start_the',
            expiredKey: 'import.mdblist_stopped_accepting_this_connection',
        );
    }

    private function http(string $token): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->timeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->withUserAgent(config()->string('tracker.http.user_agent'))
            ->withToken($token);
    }

    private function baseUrl(): string
    {
        return rtrim(config()->string('tracker.mdblist.base_url'), '/');
    }
}

<?php

namespace App\Integrations\MdbList;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\ServiceHttpClient;
use App\Models\ServiceConnection;
use App\Support\HttpRetry;
use App\Support\Payload;
use App\Support\ServiceBusy;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * MDBList's API as its reference describes it (api.mdblist.com/docs,
 * schema read 2026-11-21):
 *
 * - a user connects with their own API key (mdblist.com/preferences), sent
 *   as `?apikey=`; `/user` names the account the key belongs to. A key
 *   MDBList no longer honours is answered 401;
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

    public const PREFERENCES_URL = 'https://mdblist.com/preferences/';

    public function __construct(private readonly ServiceHttpClient $http) {}

    /**
     * Whether MDBList takes a key, asked with one request each time the
     * form is checked.
     *
     * @return string|null the account's username, when MDBList gives one
     *
     * @throws ConnectionRejected|MdbListUnavailable
     */
    public function accept(string $key): ?string
    {
        $response = $this->http->reach(MdbListService::NAME, MdbListUnavailable::class, fn (): Response => $this->request()->get('/user', ['apikey' => $key]));

        match (true) {
            HttpRetry::isOutage($response) => throw new MdbListUnavailable('import.service_answered_try_later', ['service' => MdbListService::NAME, 'status' => $response->status()]),
            in_array($response->status(), [401, 403], true) => throw new ConnectionRejected('import.mdblist_did_not_accept_this_key'),
            ! $response->successful() => throw new ConnectionRejected('import.service_answered_to', ['service' => MdbListService::NAME, 'status' => $response->status(), 'path' => '/user']),
            default => null,
        };

        return Payload::string(Payload::map($response->json()), 'username');
    }

    /**
     * Every page of a paged endpoint, one answer at a time, following
     * `pagination.next_cursor` until none comes back.
     *
     * @param  array<string, string>  $query
     * @return Generator<int, array<array-key, mixed>>
     *
     * @throws MdbListUnavailable|MdbListRejected|ServiceBusy|ConnectionExpired
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
     * @throws MdbListUnavailable|MdbListRejected|ServiceBusy|ConnectionExpired
     */
    public function get(ServiceConnection $connection, string $path, array $query = []): mixed
    {
        $key = $connection->credential('key') ?? throw new ConnectionExpired('import.mdblist_stopped_accepting_this_connection');
        $response = $this->http->unlessBusy(MdbListService::NAME, $this->send($key, $path, $query), self::DEFAULT_WAIT_SECONDS);

        // The key was regenerated on MDBList; only the new one fixes it.
        if ($response->status() === 401) {
            throw new ConnectionExpired('import.mdblist_stopped_accepting_this_connection');
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
    private function send(string $key, string $path, array $query): Response
    {
        $request = $this->http->retrying($this->request(), self::RETRIES, self::FIRST_RETRY_MS, HttpRetry::isTransient(...));

        return $this->http->reach(MdbListService::NAME, MdbListUnavailable::class, fn (): Response => $request->get($path, [...$query, 'apikey' => $key]));
    }

    /** The key rides in each request's query, as MDBList asks; ConnectionFailure keeps it out of every message. */
    private function request(): PendingRequest
    {
        return $this->http->request(config()->string('tracker.mdblist.base_url'), self::TIMEOUT_SECONDS);
    }
}

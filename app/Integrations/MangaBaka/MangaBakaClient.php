<?php

namespace App\Integrations\MangaBaka;

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
 * A MangaBaka library read with its owner's personal access token
 * (mangabaka.org/api.json, read 2026-11-21): an `mb-…` token in the
 * `x-api-key` header, made under Settings → API and apps on mangabaka.org.
 *
 * - `/v1/my/profile` names the account and the scopes the token carries;
 * - `/v2/my/library` pages by `page` up to a hundred entries, until
 *   `pagination.next` is null; `/my/*` allows 180 requests a minute, and a
 *   429 is waited out by the run. Only a dropped connection or a 5xx is
 *   asked again at once.
 */
class MangaBakaClient
{
    public const PAGE_SIZE = 100;

    private const TOKEN_PREFIX = 'mb-';

    private const LIBRARY_SCOPE = 'library.read';

    private const RETRIES = 3;

    private const FIRST_RETRY_MS = 1000;

    /** A 429 without a Retry-After waits out a minute's allowance. */
    private const DEFAULT_WAIT_SECONDS = 60;

    public function __construct(private readonly ServiceHttpClient $http) {}

    /**
     * Whether MangaBaka takes a token for reading the library, asked with one
     * request each time the form is checked; a token that cannot read the
     * library is turned down here, not on the first run.
     *
     * @return string|null the account's name, when MangaBaka gives one
     *
     * @throws ConnectionRejected|MangaBakaUnavailable
     */
    public function accept(string $token): ?string
    {
        if (! str_starts_with($token, self::TOKEN_PREFIX)) {
            throw new ConnectionRejected('import.a_mangabaka_token_starts_with');
        }

        $response = $this->http->reach(MangaBakaService::NAME, MangaBakaUnavailable::class, fn (): Response => $this->request($token)->get('/v1/my/profile'));

        $profile = Payload::map(Payload::map($response->json())['data'] ?? null);

        match (true) {
            HttpRetry::isOutage($response) => throw new MangaBakaUnavailable('import.service_answered_try_later', ['service' => MangaBakaService::NAME, 'status' => $response->status()]),
            $response->status() === 401 => throw new ConnectionRejected('import.mangabaka_did_not_accept_this_token'),
            ! $response->successful() => throw new ConnectionRejected('import.service_answered_to', ['service' => MangaBakaService::NAME, 'status' => $response->status(), 'path' => '/v1/my/profile']),
            ! in_array(self::LIBRARY_SCOPE, Payload::strings($profile['scopes'] ?? null), true) => throw new ConnectionRejected('import.this_mangabaka_token_cannot_read_your_library'),
            default => null,
        };

        return Payload::string($profile, 'preferred_username') ?? Payload::string($profile, 'nickname');
    }

    /**
     * Every page of the library, each entry with its series and lists.
     *
     * @return Generator<int, list<array<array-key, mixed>>>
     *
     * @throws MangaBakaUnavailable|MangaBakaRejected|ServiceBusy|ConnectionExpired
     */
    public function libraryPages(ServiceConnection $connection): Generator
    {
        $token = $connection->credential('token') ?? throw new ConnectionExpired('import.mangabaka_stopped_accepting_this_connection');
        $page = 1;

        do {
            // In the order entries were added, so a save during the run moves no entry between pages.
            $query = ['page' => (string) $page++, 'limit' => (string) self::PAGE_SIZE, 'sort_by' => 'created_at_asc'];
            $response = $this->http->unlessBusy(MangaBakaService::NAME, $this->send($token, '/v2/my/library', $query), self::DEFAULT_WAIT_SECONDS);

            // The token was deleted on MangaBaka; only a new one fixes it.
            if ($response->status() === 401) {
                throw new ConnectionExpired('import.mangabaka_stopped_accepting_this_connection');
            }

            if (HttpRetry::isOutage($response)) {
                throw new MangaBakaUnavailable('import.service_answered_try_later', ['service' => MangaBakaService::NAME, 'status' => $response->status()]);
            }

            // Answered and refused: the same request would be refused again.
            if (! $response->successful()) {
                throw new MangaBakaRejected('import.service_answered_to', ['service' => MangaBakaService::NAME, 'status' => $response->status(), 'path' => '/v2/my/library']);
            }

            $body = Payload::map($response->json());

            yield Payload::list($body['data'] ?? null);
        } while (Payload::string(Payload::map($body['pagination'] ?? null), 'next') !== null);
    }

    /**
     * @param  array<string, string>  $query
     *
     * @throws MangaBakaUnavailable
     */
    private function send(string $token, string $path, array $query): Response
    {
        $request = $this->http->retrying($this->request($token), self::RETRIES, self::FIRST_RETRY_MS, HttpRetry::isTransient(...));

        return $this->http->reach(MangaBakaService::NAME, MangaBakaUnavailable::class, fn (): Response => $request->get($path, $query));
    }

    private function request(string $token): PendingRequest
    {
        return $this->http->request(config()->string('tracker.mangabaka.api_url'))->withHeader('x-api-key', $token);
    }
}

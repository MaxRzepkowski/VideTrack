<?php

namespace App\Integrations\Hardcover;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ConnectionRejected;
use App\Models\ServiceConnection;
use App\Support\ConnectionFailure;
use App\Support\HttpRetry;
use App\Support\Payload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * A Hardcover reader's own library, read with the API key they made for it
 * (Hardcover's API docs: Getting Started, PAT Link Builder, Actions &
 * Scopes; schema `user_books`):
 *
 * - the key is the reader's, made on hardcover.app with the scopes to read
 *   the profile and the library; the instance's own catalogue key is never
 *   used for a library;
 * - a key without a scope is answered 403, or a GraphQL error in a 200; a
 *   key that is no good any more, 401;
 * - limits are per reader, shared with every app they use: 60 requests a
 *   minute and 5,000 a day on a free account. Pages go one a second, and
 *   only a dropped connection, a 5xx or a 429 is asked again;
 * - the query stays three levels deep (me → user_books → book, reads),
 *   the depth Hardcover means to cap queries at.
 */
class HardcoverLibraryClient
{
    public const PAGE_SIZE = 100;

    /** One page a second keeps a run inside the per-minute limit. */
    private const PAGE_INTERVAL_SECONDS = 1;

    private const TIMEOUT_SECONDS = 30;

    private const RETRIES = 4;

    private const FIRST_RETRY_MS = 2000;

    private const LIBRARY_QUERY = <<<'GRAPHQL'
        query Library($limit: Int!, $offset: Int!) {
          me {
            user_books(limit: $limit, offset: $offset, order_by: {id: asc}) {
              book_id
              status_id
              rating
              date_added
              first_started_reading_date
              last_read_date
              book { title }
              user_book_reads(order_by: {id: asc}) { started_at finished_at progress_pages }
            }
          }
        }
        GRAPHQL;

    /** The scopes the import needs, for the link that opens Hardcover's new key form with them ticked. */
    private const SCOPES = 'read:me:content read:library';

    private const NEW_KEY_URL = 'https://hardcover.app/account/api/keys/new';

    private const CHECK_QUERY = '{ me { username user_books(limit: 1) { book_id } } }';

    /** Where the reader makes a key with the scopes the import needs. */
    public static function newKeyUrl(): string
    {
        return self::NEW_KEY_URL.'?'.http_build_query(['scope' => self::SCOPES]);
    }

    /**
     * Whether Hardcover takes a key for reading the library, asked with one
     * request each time the form is checked; a key that cannot read it is
     * turned down here, not on the first run.
     *
     * @return string|null the reader's username, when Hardcover gives one
     *
     * @throws ConnectionRejected|HardcoverUnavailable
     */
    public function accept(string $key): ?string
    {
        try {
            $response = $this->http($key)->post($this->graphqlUrl(), ['query' => self::CHECK_QUERY]);
        } catch (ConnectionException $e) {
            throw new HardcoverUnavailable('import.service_unreachable', ['service' => HardcoverService::NAME, 'reason' => ConnectionFailure::reason($e)]);
        }

        $me = Payload::list($response->json('data.me'))[0] ?? [];

        match (true) {
            HttpRetry::isOutage($response) => throw new HardcoverUnavailable('import.service_answered_try_later', ['service' => HardcoverService::NAME, 'status' => $response->status()]),
            $response->status() === 401 => throw new ConnectionRejected('import.hardcover_did_not_accept_this_key'),
            $response->status() === 403, $response->successful() && ($response->json('errors') !== null || ! is_array($me['user_books'] ?? null)) => throw new ConnectionRejected('import.this_hardcover_key_cannot_read_your_library'),
            ! $response->successful() => throw new ConnectionRejected('import.service_answered_to', ['service' => HardcoverService::NAME, 'status' => $response->status(), 'path' => '/v1/graphql']),
            default => null,
        };

        return Payload::string($me, 'username');
    }

    /**
     * One page of the library, in the order Hardcover keeps it. The first
     * page goes at once; each later one waits its turn.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws HardcoverUnavailable|HardcoverRejected|ConnectionExpired
     */
    public function libraryPage(ServiceConnection $connection, int $page): array
    {
        $key = $connection->credential('key') ?? throw new ConnectionExpired('import.hardcover_stopped_accepting_this_connection');

        if ($page > 0) {
            Sleep::for(self::PAGE_INTERVAL_SECONDS)->seconds();
        }

        $variables = ['limit' => self::PAGE_SIZE, 'offset' => $page * self::PAGE_SIZE];
        $response = $this->query($key, $variables);

        // The key was deleted on Hardcover, or ran out; only a new one fixes it.
        if ($response->status() === 401) {
            throw new ConnectionExpired('import.hardcover_stopped_accepting_this_connection');
        }

        if (HttpRetry::isOutage($response)) {
            throw new HardcoverUnavailable('import.service_answered_try_later', ['service' => HardcoverService::NAME, 'status' => $response->status()]);
        }

        $me = Payload::list($response->json('data.me'))[0] ?? null;
        $books = is_array($me) ? ($me['user_books'] ?? null) : null;

        // Answered and refused, a GraphQL error in a 200, or an answer
        // without the library: the same query would fare no better. The
        // service's own wording is not carried.
        if (! $response->successful() || $response->json('errors') !== null || ! is_array($books)) {
            throw new HardcoverRejected('import.hardcover_refused_the_library_query', ['status' => $response->status()]);
        }

        return Payload::list($books);
    }

    /**
     * @param  array<string, int>  $variables
     */
    private function query(string $key, array $variables): Response
    {
        try {
            return $this->http($key)
                ->retry(self::RETRIES, fn (int $attempt): int => self::FIRST_RETRY_MS * 2 ** ($attempt - 1), HttpRetry::isTransientOrThrottled(...), throw: false)
                ->post($this->graphqlUrl(), ['query' => self::LIBRARY_QUERY, 'variables' => $variables]);
        } catch (ConnectionException $e) {
            throw new HardcoverUnavailable('import.service_unreachable', ['service' => HardcoverService::NAME, 'reason' => ConnectionFailure::reason($e)]);
        }
    }

    private function http(string $key): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->withUserAgent(config()->string('tracker.http.user_agent'))
            ->withToken($key);
    }

    private function graphqlUrl(): string
    {
        return config()->string('tracker.hardcover.endpoint');
    }
}

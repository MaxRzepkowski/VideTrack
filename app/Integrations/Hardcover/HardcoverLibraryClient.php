<?php

namespace App\Integrations\Hardcover;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\Data\DeviceGrant;
use App\Integrations\Connections\DeviceGrantClient;
use App\Integrations\Connections\GrantUnavailable;
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
 * A Hardcover reader's own library, read with the token their account
 * granted this instance (Hardcover's API docs: OAuth, Getting Started →
 * Rate Limits; schema `user_books`):
 *
 * - the grant comes from the Device Authorization Grant of an app the
 *   operator registered, asking only to read the profile and the library;
 *   the instance's own catalogue key is never used for a library;
 * - access tokens live a week; refresh tokens rotate on every use, and one
 *   spent twice revokes the whole grant — DeviceGrantClient's refresh is
 *   built for that;
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

    public function __construct(private readonly DeviceGrantClient $grants) {}

    /**
     * The username, for the card — read with a token straight from the
     * device flow, before any connection row exists. Only a label: a dropped
     * connection here leaves it unknown rather than losing the grant.
     */
    public function username(string $accessToken): ?string
    {
        try {
            $response = $this->http($accessToken)->post($this->graphqlUrl(), ['query' => '{ me { username } }']);
        } catch (ConnectionException) {
            return null;
        }

        $me = Payload::list($response->json('data.me'))[0] ?? null;

        return $response->successful() && is_array($me) ? Payload::string($me, 'username') : null;
    }

    /**
     * One page of the library, in the order Hardcover keeps it. The first
     * page goes at once; each later one waits its turn.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws HardcoverUnavailable|HardcoverRejected|ConnectionExpired|GrantUnavailable
     */
    public function libraryPage(ServiceConnection $connection, int $page): array
    {
        if ($page > 0) {
            Sleep::for(self::PAGE_INTERVAL_SECONDS)->seconds();
        }

        $variables = ['limit' => self::PAGE_SIZE, 'offset' => $page * self::PAGE_SIZE];
        $response = $this->grants->call($this->grant(), $connection, fn (string $token): Response => $this->query($token, $variables));

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
    private function query(string $token, array $variables): Response
    {
        try {
            return $this->http($token)
                ->retry(self::RETRIES, fn (int $attempt): int => self::FIRST_RETRY_MS * 2 ** ($attempt - 1), HttpRetry::isTransientOrThrottled(...), throw: false)
                ->post($this->graphqlUrl(), ['query' => self::LIBRARY_QUERY, 'variables' => $variables]);
        } catch (ConnectionException $e) {
            throw new HardcoverUnavailable('import.service_unreachable', ['service' => HardcoverService::NAME, 'reason' => ConnectionFailure::reason($e)]);
        }
    }

    /** Where the service keeps its device grant, for connecting and for every call made with it. */
    public function grant(): DeviceGrant
    {
        return new DeviceGrant(
            service: HardcoverService::NAME,
            baseUrl: config()->string('tracker.hardcover.oauth_url'),
            clientId: config()->string('tracker.hardcover.client_id'),
            scope: 'read:me:content read:library',
            devicePath: '/oauth2/device',
            tokenPath: '/oauth2/token',
            revokePath: '/oauth2/revoke',
            rejectedKey: 'import.hardcover_did_not_accept_this_instance',
            notStartedKey: 'import.the_service_could_not_start_the',
            expiredKey: 'import.hardcover_stopped_accepting_this_connection',
        );
    }

    private function http(string $token): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->withUserAgent(config()->string('tracker.http.user_agent'))
            ->withToken($token);
    }

    private function graphqlUrl(): string
    {
        return config()->string('tracker.hardcover.endpoint');
    }
}

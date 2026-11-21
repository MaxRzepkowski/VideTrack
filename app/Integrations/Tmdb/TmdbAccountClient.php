<?php

namespace App\Integrations\Tmdb;

use App\Catalog\Providers\Tmdb\TmdbKey;
use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ConnectionRejected;
use App\Models\ServiceConnection;
use App\Support\ConnectionFailure;
use App\Support\HttpRetry;
use App\Support\Payload;
use App\Support\ServiceBusy;
use Closure;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A TMDB account read through API v3 with the instance's key and the
 * session the user approved (developer.themoviedb.org, read 2026-11-21):
 * a request token, approved on themoviedb.org, becomes a session id that
 * holds until it is deleted. The session id is a password: it travels in
 * the query string, as v3 wants it, and never goes into an exception.
 *
 * Account lists page by `page` up to `total_pages`. A refused session is
 * final; only a dropped connection or a 5xx is asked again at once, and a
 * 429 is waited out by the run.
 */
class TmdbAccountClient
{
    private const TIMEOUT_SECONDS = 30;

    private const RETRIES = 3;

    private const FIRST_RETRY_MS = 1000;

    /** A 429 without a Retry-After waits ten seconds: TMDB's limits are per second. */
    private const DEFAULT_WAIT_SECONDS = 10;

    /**
     * A request token for the user to approve.
     *
     * @throws ConnectionRejected|TmdbUnavailable|ServiceBusy
     */
    public function requestToken(): string
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->get('/authentication/token/new'));
        $this->refuseIfInstanceRefused($response, ConnectionRejected::class);

        return ($response->successful() ? Payload::string(Payload::map($response->json()), 'request_token') : null)
            ?? throw new ConnectionRejected('import.the_service_could_not_start_the');
    }

    /**
     * The session the approved token stands for; a token the user did not
     * approve, or turned down, makes none.
     *
     * @throws ConnectionRejected|TmdbUnavailable|ServiceBusy
     */
    public function createSession(string $requestToken): string
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->post('/authentication/session/new', ['request_token' => $requestToken]));
        $this->refuseIfInstanceRefused($response, ConnectionRejected::class);

        return ($response->successful() ? Payload::string(Payload::map($response->json()), 'session_id') : null)
            ?? throw new ConnectionRejected('import.tmdb_connection_was_not_approved');
    }

    /**
     * The account behind a session: its id, which the account paths take,
     * and its username. `/account` without an id answers for the session.
     *
     * @return array{id: string, username: string|null}
     *
     * @throws ConnectionRejected|TmdbUnavailable|ServiceBusy
     */
    public function account(string $sessionId): array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->get('/account', ['session_id' => $sessionId]));
        $this->refuseIfInstanceRefused($response, ConnectionRejected::class);
        $account = $response->successful() ? Payload::map($response->json()) : [];

        return [
            'id' => Payload::id($account['id'] ?? null) ?? throw new ConnectionRejected('import.tmdb_connection_was_not_approved'),
            'username' => Payload::string($account, 'username'),
        ];
    }

    /** Ends the session at TMDB; it answers whatever happened, so nothing is checked. */
    public function deleteSession(ServiceConnection $connection): void
    {
        $session = $connection->credential('session_id');

        if ($session !== null) {
            $this->send(fn (PendingRequest $http): Response => $http->delete('/authentication/session', ['session_id' => $session]));
        }
    }

    /**
     * Every page of an account listing (`watchlist/movies`, `rated/tv`…). A
     * session TMDB no longer knows ends the connection.
     *
     * @return Generator<int, array<array-key, mixed>>
     *
     * @throws ConnectionExpired|TmdbRejected|TmdbUnavailable|ServiceBusy
     */
    public function accountPages(ServiceConnection $connection, string $listing): Generator
    {
        $account = $connection->setting('account_id');
        $session = $connection->credential('session_id');

        if ($account === null || $session === null) {
            throw new ConnectionExpired('import.tmdb_stopped_accepting_this_connection');
        }

        $path = '/account/'.rawurlencode($account).'/'.$listing;

        yield from $this->pages($path, ['session_id' => $session], function (Response $response, ?TmdbStatus $status) use ($path): never {
            throw match (true) {
                in_array($status, [TmdbStatus::AuthenticationFailed, TmdbStatus::SessionDenied, TmdbStatus::SessionNotFound], true) => new ConnectionExpired('import.tmdb_stopped_accepting_this_connection'),
                default => $this->refused($response, $path),
            };
        });
    }

    /**
     * Every page of one of the user's lists, as v3 shows a list: without
     * the session; one it keeps private, or one gone, says so.
     *
     * @return Generator<int, array<array-key, mixed>>
     *
     * @throws TmdbListPrivate|TmdbNotFound|TmdbRejected|TmdbUnavailable|ServiceBusy
     */
    public function listPages(string $listId): Generator
    {
        $path = '/list/'.rawurlencode($listId);

        yield from $this->pages($path, [], function (Response $response, ?TmdbStatus $status) use ($path): never {
            throw match (true) {
                $status === TmdbStatus::ResourcePrivate => new TmdbListPrivate('import.service_answered_to', ['service' => TmdbService::NAME, 'status' => $response->status(), 'path' => $path]),
                $response->status() === 404 => new TmdbNotFound('import.service_knows_nothing_at', ['service' => TmdbService::NAME, 'path' => $path]),
                default => $this->refused($response, $path),
            };
        });
    }

    /**
     * @param  array<string, string>  $query
     * @param  Closure(Response, ?TmdbStatus): never  $refusal  what an answered refusal of this path means
     * @return Generator<int, array<array-key, mixed>>
     *
     * @throws TmdbRejected|TmdbUnavailable|ServiceBusy
     */
    private function pages(string $path, array $query, Closure $refusal): Generator
    {
        $page = 1;

        do {
            $response = $this->send(fn (PendingRequest $http): Response => $http->get($path, [...$query, 'page' => (string) $page]));
            $this->refuseIfInstanceRefused($response, TmdbRejected::class);

            if (! $response->successful()) {
                $refusal($response, $this->status($response));
            }

            $body = Payload::map($response->json());

            yield $body;

            $last = Payload::int($body, 'total_pages') ?? 1;
        } while ($page++ < $last);
    }

    private function refused(Response $response, string $path): TmdbRejected
    {
        return new TmdbRejected('import.service_answered_to', ['service' => TmdbService::NAME, 'status' => $response->status(), 'path' => $path]);
    }

    /**
     * The instance's key refused is said as such, whatever was asked.
     *
     * @param  class-string<ConnectionRejected|TmdbRejected>  $as
     *
     * @throws ConnectionRejected|TmdbRejected
     */
    private function refuseIfInstanceRefused(Response $response, string $as): void
    {
        if ($this->status($response)?->refusesTheInstance() === true) {
            throw new $as('import.tmdb_did_not_accept_this_instance');
        }
    }

    private function status(Response $response): ?TmdbStatus
    {
        return Payload::intEnum(Payload::map($response->json()), 'status_code', TmdbStatus::class);
    }

    /**
     * One request; an outage or a wait is thrown, any other answer is the
     * caller's to read.
     *
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws TmdbUnavailable|ServiceBusy
     */
    private function send(callable $request): Response
    {
        try {
            $response = $request(TmdbKey::on(
                Http::baseUrl(rtrim(config()->string('tracker.tmdb.base_url'), '/'))
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->withUserAgent(config()->string('tracker.http.user_agent'))
                    ->retry(self::RETRIES, fn (int $attempt): int => self::FIRST_RETRY_MS * 2 ** ($attempt - 1), HttpRetry::isTransient(...), throw: false),
            ));
        } catch (ConnectionException $e) {
            throw new TmdbUnavailable('import.service_unreachable', ['service' => TmdbService::NAME, 'reason' => ConnectionFailure::reason($e)]);
        }

        if ($response->serverError()) {
            throw new TmdbUnavailable('import.service_answered_try_later', ['service' => TmdbService::NAME, 'status' => $response->status()]);
        }

        if ($response->status() === 429) {
            throw new ServiceBusy('import.service_asked_to_wait', HttpRetry::retryAfter($response, self::DEFAULT_WAIT_SECONDS), ['service' => TmdbService::NAME]);
        }

        return $response;
    }
}

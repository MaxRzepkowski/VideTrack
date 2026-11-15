<?php

namespace App\Integrations\Tracearr;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Data\PinnedAddress;
use App\Models\ServiceConnection;
use App\Support\ConnectionFailure;
use App\Support\HttpRetry;
use App\Support\Payload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Tracearr's public API v2, as its source defines it
 * (connorgallopo/Tracearr@ef0fb45, apps/server/src/routes/publicV2):
 * `{address}/api/v2/public/…`, `Authorization: Bearer trr_pub_…`, a key
 * that must belong to the instance owner, 240 requests a minute per key by
 * default, cursor pages of at most 100.
 *
 * The address and key are the user's own, pasted in, so a request goes
 * only to the IP `UserAddresses` approved and never follows a redirect. A
 * rejected key is final and never retried; only a dropped connection, a 5xx
 * or a 429 is.
 */
class TracearrClient
{
    public const KEY_PREFIX = 'trr_pub_';

    private const TIMEOUT_SECONDS = 30;

    private const PAGE_SIZE = 100;

    /**
     * The address as typed, reduced to its base: scheme and host (and a
     * reverse-proxy path, if any), without a trailing slash or API path.
     * Whether it is a web address at all is `UserAddresses`' to say.
     */
    public function normalizeAddress(string $address): string
    {
        return (string) preg_replace('#/api(/v\d+(/public)?)?$#', '', rtrim(trim($address), '/'));
    }

    /**
     * The people Tracearr watches, to pick the user from. One request per
     * page; the first answer decides whether the key is any good.
     *
     * @return list<array{id: string, username: string}>
     *
     * @throws ConnectionRejected
     */
    public function users(PinnedAddress $address, string $key): array
    {
        if (! str_starts_with($key, self::KEY_PREFIX)) {
            throw new ConnectionRejected('import.a_tracearr_api_key_starts_with');
        }

        $users = [];
        $cursor = null;

        do {
            try {
                $response = $this->http($address, $key)->get('/users', array_filter([
                    'pageSize' => self::PAGE_SIZE,
                    'cursor' => $cursor,
                ]));
            } catch (ConnectionException) {
                throw new ConnectionRejected('import.tracearr_could_not_be_reached_at');
            }

            match (true) {
                $response->status() === 401 => throw new ConnectionRejected('import.tracearr_did_not_accept_this_api'),
                $response->status() === 403 => throw new ConnectionRejected('import.this_api_key_does_not_belong'),
                $response->status() === 404 => throw new ConnectionRejected('import.there_is_no_tracearr_api_at'),
                ! $response->successful() || ! is_array($response->json('data')) => throw new ConnectionRejected('import.tracearr_could_not_be_read_at'),
                default => null,
            };

            foreach ((array) $response->json('data') as $user) {
                if (is_array($user) && is_string($user['id'] ?? null)) {
                    $users[] = ['id' => $user['id'], 'username' => is_string($user['username'] ?? null) ? $user['username'] : $user['id']];
                }
            }

            $cursor = $response->json('meta.nextCursor');
        } while (is_string($cursor) && $cursor !== '');

        return $users;
    }

    /**
     * One page of a list endpoint, with the connection's key.
     *
     * @param  array<string, string|int>  $query
     * @return array{data: list<array<array-key, mixed>>, nextCursor: string|null}
     *
     * @throws TracearrUnavailable|TracearrRejected|TracearrNotFound|ConnectionExpired
     */
    public function page(ServiceConnection $connection, PinnedAddress $address, string $path, array $query): array
    {
        $body = $this->get($connection, $address, $path, [...$query, 'pageSize' => self::PAGE_SIZE]);

        return [
            'data' => Payload::list($body['data'] ?? null),
            'nextCursor' => Payload::string(Payload::map($body['meta'] ?? null), 'nextCursor'),
        ];
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<mixed>|null null when Tracearr does not know the thing
     *
     * @throws TracearrUnavailable|TracearrRejected|ConnectionExpired
     */
    public function find(ServiceConnection $connection, PinnedAddress $address, string $path, array $query = []): ?array
    {
        try {
            return $this->get($connection, $address, $path, $query);
        } catch (TracearrNotFound) {
            return null;
        }
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<mixed>
     *
     * @throws TracearrUnavailable|TracearrRejected|TracearrNotFound|ConnectionExpired
     */
    private function get(ServiceConnection $connection, PinnedAddress $address, string $path, array $query): array
    {
        try {
            $response = $this->http($address, (string) $connection->credential('key'))
                ->retry(3, fn (int $attempt): int => 1000 * 2 ** ($attempt - 1), HttpRetry::isTransientOrThrottled(...), throw: false)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new TracearrUnavailable('import.service_unreachable', ['service' => 'Tracearr', 'reason' => ConnectionFailure::reason($e)]);
        }

        // The key was revoked or lost the owner role; only a new key fixes it.
        if (in_array($response->status(), [401, 403], true)) {
            throw new ConnectionExpired('import.tracearr_stopped_accepting_this_api_key');
        }

        if ($response->status() === 404) {
            throw new TracearrNotFound('import.service_knows_nothing_at', ['service' => 'Tracearr', 'path' => $path]);
        }

        if (HttpRetry::isOutage($response)) {
            throw new TracearrUnavailable('import.service_answered_to', ['service' => 'Tracearr', 'status' => $response->status(), 'path' => $path]);
        }

        // Answered and refused: the same request would be refused again.
        if (! $response->successful()) {
            throw new TracearrRejected('import.service_answered_to', ['service' => 'Tracearr', 'status' => $response->status(), 'path' => $path]);
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    private function http(PinnedAddress $address, string $key): PendingRequest
    {
        return Http::baseUrl($address->url.'/api/v2/public')
            ->withoutRedirecting()
            ->withOptions(['curl' => [CURLOPT_RESOLVE => [$address->curlResolve()]]])
            ->timeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->withToken($key)
            ->withUserAgent(config()->string('tracker.http.user_agent'));
    }
}

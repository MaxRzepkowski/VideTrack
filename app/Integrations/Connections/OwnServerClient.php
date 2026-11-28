<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Data\PinnedAddress;
use App\Integrations\ServiceHttpClient;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Support\HttpRetry;
use App\Support\ServiceBusy;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * The transport to a server the user runs (Tracearr and the like) at an
 * address they typed. A request goes only to the IP `UserAddresses`
 * approved and never follows a redirect; the service's client adds its own
 * way of signing in to the request this hands out.
 *
 * Connecting asks once and turns every failure into a reason shown under the
 * form. A run waits out a dropped connection, a 5xx or a 429 and never asks
 * again after any other answer: a refused key is final.
 */
final readonly class OwnServerClient
{
    /** How a run asks again: three tries, waiting twice as long each time from a second. */
    public const TRIES = 3;

    public const FIRST_RETRY_MS = 1000;

    /** A 429 still standing after the tries waits a minute, unless the server names a wait. */
    public const DEFAULT_WAIT_SECONDS = 60;

    public function __construct(private UserAddresses $addresses, private ServiceHttpClient $http) {}

    /**
     * The stored address, checked again before every run: the operator's
     * list may have changed, and the name may now resolve somewhere else. A
     * refused address needs the user to connect again; a name that resolved
     * to nothing may resolve on the next attempt.
     *
     * @throws ConnectionExpired|OwnServerUnavailable
     */
    public function address(ServiceConnection $connection, string $service): PinnedAddress
    {
        try {
            return $this->addresses->pin((string) $connection->credential('url'), $connection->user);
        } catch (AddressRefused $refused) {
            throw new ConnectionExpired($refused->key);
        } catch (AddressNotFound) {
            throw new OwnServerUnavailable('import.service_could_not_be_reached_at', ['service' => $service]);
        }
    }

    /**
     * The address the user typed, reduced to its base (scheme and host, and
     * a reverse-proxy path if any: no trailing slash, no API path) and
     * checked for the owner. Whether it is a web address at all is
     * `UserAddresses`' to say.
     *
     * @param  string  $apiPathPattern  the API's path the user may have pasted with it, as an unanchored
     *                                  PCRE fragment without `#` (Tracearr's is `/api(/v\d+(/public)?)?`)
     *
     * @throws UnusableAddress
     */
    public function pinTyped(string $typed, string $apiPathPattern, User $owner): PinnedAddress
    {
        return $this->addresses->pin((string) preg_replace('#'.$apiPathPattern.'$#', '', rtrim(trim($typed), '/')), $owner);
    }

    /** A request to `$base` (the API's path under the address) at the approved IP. */
    public function to(PinnedAddress $address, string $base): PendingRequest
    {
        return $address->hold($this->http->request($address->url.$base));
    }

    /**
     * One request while connecting, with what the user typed; never retried.
     *
     * @param  array<string, mixed>  $query
     * @param  array<int, string>  $refusals  status => the reason a service gives it, before the common ones
     * @return array<array-key, mixed>
     *
     * @throws ConnectionRejected
     */
    public function check(PendingRequest $request, string $service, string $path, array $query = [], array $refusals = []): array
    {
        try {
            $response = $request->get($path, $query);
        } catch (ConnectionException) {
            throw new ConnectionRejected('import.service_could_not_be_reached_at', ['service' => $service]);
        }

        $body = $response->json();
        $refusal = $refusals[$response->status()] ?? null;

        if ($refusal !== null) {
            throw new ConnectionRejected($refusal, ['service' => $service]);
        }

        match (true) {
            $response->status() === 401 => throw new ConnectionRejected('import.service_did_not_accept_this_key', ['service' => $service]),
            $response->status() === 404 => throw new ConnectionRejected('import.there_is_no_service_api_at', ['service' => $service]),
            ! $response->successful() || ! is_array($body) => throw new ConnectionRejected('import.service_could_not_be_read_at', ['service' => $service]),
            default => null,
        };

        return $body;
    }

    /**
     * One request of a run, with the stored key.
     *
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     *
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ServiceBusy|ConnectionExpired
     */
    public function read(PendingRequest $request, string $service, string $path, array $query = []): array
    {
        return $this->answer($request, $service, $path, fn (PendingRequest $retrying): Response => $retrying->get($path, $query), absent: []);
    }

    /**
     * One request of a run that asks with a body, for a server that lists
     * by POST (a filter, a page). It reads all the same: nothing is written.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     *
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ServiceBusy|ConnectionExpired
     */
    public function search(PendingRequest $request, string $service, string $path, array $body, array $query = []): array
    {
        return $this->answer($request, $service, $path, fn (PendingRequest $retrying): Response => $retrying->withQueryParameters($query)->post($path, $body), absent: []);
    }

    /**
     * One thing of the user's that the key may not be allowed to see (an
     * item in a library the user lost access to): a 403 there is about the
     * thing, not the key, so it answers null as a 404 does. A 401 still
     * expires the connection.
     *
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>|null
     *
     * @throws OwnServerUnavailable|OwnServerRejected|ServiceBusy|ConnectionExpired
     */
    public function find(PendingRequest $request, string $service, string $path, array $query = []): ?array
    {
        try {
            return $this->answer($request, $service, $path, fn (PendingRequest $retrying): Response => $retrying->get($path, $query), absent: [403]);
        } catch (OwnServerNotFound) {
            return null;
        }
    }

    /**
     * @param  Closure(PendingRequest): Response  $send  the request itself, on the retrying client
     * @param  list<int>  $absent  statuses that mean the thing is not there for this key, beside a 404
     * @return array<array-key, mixed>
     *
     * @throws OwnServerUnavailable|OwnServerRejected|OwnServerNotFound|ServiceBusy|ConnectionExpired
     */
    private function answer(PendingRequest $request, string $service, string $path, Closure $send, array $absent): array
    {
        $retrying = $this->http->retrying($request, self::TRIES, self::FIRST_RETRY_MS, HttpRetry::isTransientOrThrottled(...));
        $response = $this->http->unlessBusy($service, $this->http->reach($service, OwnServerUnavailable::class, fn (): Response => $send($retrying)), self::DEFAULT_WAIT_SECONDS);

        if ($response->status() === 404 || in_array($response->status(), $absent, true)) {
            throw new OwnServerNotFound('import.service_knows_nothing_at', ['service' => $service, 'path' => $path]);
        }

        // The key was revoked or lost its rights; only a new key fixes it.
        if (in_array($response->status(), [401, 403], true)) {
            throw new ConnectionExpired('import.the_server_stopped_accepting_this_key');
        }

        if (HttpRetry::isOutage($response)) {
            throw new OwnServerUnavailable('import.service_answered_to', ['service' => $service, 'status' => $response->status(), 'path' => $path]);
        }

        // Answered and refused: the same request would be refused again.
        if (! $response->successful()) {
            throw new OwnServerRejected('import.service_answered_to', ['service' => $service, 'status' => $response->status(), 'path' => $path]);
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}

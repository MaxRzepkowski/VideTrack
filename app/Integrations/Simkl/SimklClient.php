<?php

namespace App\Integrations\Simkl;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\DevicePoll;
use App\Integrations\Connections\TokenStore;
use App\Models\ServiceConnection;
use App\Support\ConnectionFailure;
use App\Support\HttpRetry;
use App\Support\Payload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * Simkl's API as its rules ask to be called (api.simkl.org, read 2026-09-27):
 *
 * - every API call carries client_id, app-name and app-version in the query
 *   and a User-Agent naming the app; the OAuth endpoints take a form body;
 * - calls go one after another (10 GET/s per client id and token);
 * - 429 and 5xx are waited out — doubling from one second, five attempts —
 *   and nothing else is retried: an answered 4xx is final;
 * - AUTH V2 access tokens live seven days and one grant holds exactly one
 *   live token, so a refresh by anyone cuts off every copy. Refreshing is
 *   serialized per connection, and a 401 first re-reads the stored token —
 *   another worker may already have refreshed — before refreshing itself.
 */
class SimklClient
{
    private const TIMEOUT_SECONDS = 60;

    /** Refresh this long before expiry rather than on the first 401. */
    private const REFRESH_MARGIN_SECONDS = 86400;

    public function __construct(private readonly TokenStore $tokenStore) {}

    public function configured(): bool
    {
        return $this->clientId() !== '';
    }

    /**
     * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string|null, expires_in: int, interval: int}
     *
     * @throws ConnectionRejected|SimklUnavailable
     */
    public function requestDeviceCode(): array
    {
        $response = $this->oauth('/oauth2/device', ['client_id' => $this->clientId(), 'scope' => 'media:read']);

        if ($response->status() === 401) {
            throw new ConnectionRejected('import.simkl_did_not_accept_this_instance');
        }

        $this->throwIfDown($response);

        if (! $response->successful() || ! is_string($response->json('device_code')) || ! is_string($response->json('user_code'))) {
            throw new ConnectionRejected('import.simkl_could_not_start_the_connection');
        }

        return [
            'device_code' => (string) $response->json('device_code'),
            'user_code' => (string) $response->json('user_code'),
            'verification_uri' => is_string($response->json('verification_uri')) ? (string) $response->json('verification_uri') : 'https://simkl.com/pin',
            'verification_uri_complete' => is_string($response->json('verification_uri_complete')) ? (string) $response->json('verification_uri_complete') : null,
            'expires_in' => is_int($response->json('expires_in')) ? (int) $response->json('expires_in') : 900,
            'interval' => is_int($response->json('interval')) ? (int) $response->json('interval') : 5,
        ];
    }

    /**
     * One poll of the device flow: the tokens once approved, otherwise what
     * to do next. Simkl never reports a refusal — it stays "pending".
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int}|DevicePoll
     *
     * @throws ConnectionRejected|SimklUnavailable
     */
    public function pollDeviceCode(string $deviceCode): array|DevicePoll
    {
        $response = $this->oauth('/oauth2/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $this->clientId(),
            'device_code' => $deviceCode,
        ]);

        if ($response->successful()) {
            return $this->tokens($response);
        }

        if ($response->status() === 401) {
            throw new ConnectionRejected('import.simkl_did_not_accept_this_instance');
        }

        // Down for a moment is not "the code expired": the next poll asks again.
        $this->throwIfDown($response);

        return match ($response->json('error')) {
            'authorization_pending' => DevicePoll::Pending,
            'slow_down' => DevicePoll::SlowDown,
            default => DevicePoll::Expired,
        };
    }

    /**
     * Ends the grant at Simkl. It answers 200 whatever happened (RFC 7009),
     * so there is nothing to check.
     */
    public function revoke(ServiceConnection $connection): void
    {
        $token = $connection->credential('refresh_token') ?? $connection->credential('access_token');

        if ($token !== null) {
            $this->oauth('/oauth2/revoke', ['client_id' => $this->clientId(), 'token' => $token]);
        }
    }

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
     * @throws SimklUnavailable|SimklRejected|ConnectionExpired
     */
    public function get(ServiceConnection $connection, string $path, array $query = []): array
    {
        $token = $this->freshToken($connection);
        $response = $this->send($token, $path, $query);

        if ($response->status() === 401) {
            $response = $this->send($this->tokenAfter401($connection, $token), $path, $query);
        }

        if ($response->status() === 401) {
            throw $this->grantGone();
        }

        $this->throwIfDown($response);

        // Answered and refused: the same request would be refused again.
        if (! $response->successful()) {
            throw new SimklRejected('import.service_answered_to', ['service' => 'Simkl', 'status' => $response->status(), 'path' => $path]);
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
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
            throw new SimklUnavailable('import.service_unreachable', ['service' => 'Simkl', 'reason' => ConnectionFailure::reason($e)]);
        }
    }

    private function freshToken(ServiceConnection $connection): string
    {
        $expiresAt = (int) ($connection->credential('expires_at') ?? 0);

        if ($expiresAt - now()->getTimestamp() > self::REFRESH_MARGIN_SECONDS) {
            return (string) $connection->credential('access_token');
        }

        return $this->refresh($connection, (string) $connection->credential('access_token'));
    }

    /**
     * A 401 looks the same whether the token expired or another worker
     * refreshed the grant (which kills every other copy). The stored token
     * tells them apart: if it moved on, use it; only if not, refresh.
     */
    private function tokenAfter401(ServiceConnection $connection, string $rejected): string
    {
        $stored = $this->tokenStore->storedAccessToken($connection);

        return $stored !== $rejected ? $stored : $this->refresh($connection, $rejected);
    }

    /**
     * One refresh per connection at a time. Inside the lock the stored token
     * is read again: if someone refreshed while this worker waited, theirs
     * is used and the grant is left alone.
     */
    private function refresh(ServiceConnection $connection, string $stale): string
    {
        $token = Cache::lock("simkl-refresh:{$connection->id}", 30)->block(20, function () use ($connection, $stale): string {
            $stored = $this->tokenStore->storedAccessToken($connection);

            if ($stored !== $stale) {
                return $stored;
            }

            $response = $this->oauth('/oauth2/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId(),
                'refresh_token' => (string) $connection->credential('refresh_token'),
            ]);

            if ($response->clientError()) {
                throw $this->grantGone();
            }

            if (! $response->successful()) {
                throw new SimklUnavailable('import.service_answered_token_refresh', ['service' => 'Simkl', 'status' => $response->status()]);
            }

            $tokens = $this->tokens($response);

            $this->tokenStore->save($connection, $tokens);

            return $tokens['access_token'];
        });

        // The lock hands back whatever the closure returned.
        return is_string($token) ? $token : throw new LogicException('A Simkl token refresh ends in a token.');
    }

    /**
     * The grant is gone (revoked, or the 180-day refresh window lapsed).
     */
    private function grantGone(): ConnectionExpired
    {
        return new ConnectionExpired('import.simkl_stopped_accepting_this_connection_connect');
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: int}
     */
    private function tokens(Response $response): array
    {
        $answer = Payload::map($response->json());

        return [
            'access_token' => Payload::text($answer, 'access_token'),
            // Non-rotating: the same refresh token comes back every time.
            'refresh_token' => Payload::text($answer, 'refresh_token'),
            'expires_at' => now()->addSeconds(Payload::int($answer, 'expires_in') ?? 604800)->getTimestamp(),
        ];
    }

    /**
     * Simkl failing on its side, or asking us to wait: asking again later
     * is all there is to do.
     *
     * @throws SimklUnavailable
     */
    private function throwIfDown(Response $response): void
    {
        if (HttpRetry::isOutage($response)) {
            throw new SimklUnavailable('import.service_answered_try_later', ['service' => 'Simkl', 'status' => $response->status()]);
        }
    }

    /**
     * @param  array<string, string>  $form
     */
    private function oauth(string $path, array $form): Response
    {
        try {
            return $this->http()->asForm()->post($path, $form);
        } catch (ConnectionException $e) {
            throw new SimklUnavailable('import.service_unreachable', ['service' => 'Simkl', 'reason' => ConnectionFailure::reason($e)]);
        }
    }

    private function api(string $token): PendingRequest
    {
        return $this->http()->withToken($token);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim(config()->string('tracker.simkl.base_url'), '/'))
            ->timeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->withUserAgent(config()->string('tracker.http.user_agent'));
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

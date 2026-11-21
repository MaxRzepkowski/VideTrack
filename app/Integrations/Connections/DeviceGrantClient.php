<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Data\DeviceAuthorization;
use App\Integrations\Connections\Data\DeviceGrant;
use App\Models\ServiceConnection;
use App\Support\ConnectionFailure;
use App\Support\HttpRetry;
use App\Support\Payload;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * The OAuth side of a connection made through the device flow (RFC 8628),
 * the same for every service that offers it: asking for a code, polling
 * until the user approves, and keeping the access token fresh.
 *
 * Refreshing is the delicate part. Some services rotate the refresh token
 * on every use and revoke the whole grant when a spent one comes back;
 * others keep one live access token per grant, so a refresh by anyone cuts
 * off every other copy. So a refresh is sent once and never retried, runs
 * one at a time per connection, and re-reads the stored token first: a
 * worker that waited uses the refresh another one made. A 401 re-reads the
 * stored token too before refreshing. A refused refresh means the grant is
 * gone; a 429 or a 5xx only that the service is busy.
 *
 * Nothing here retries an answered 4xx.
 */
class DeviceGrantClient
{
    private const TIMEOUT_SECONDS = 60;

    /** Refresh this long before expiry rather than on the first 401. */
    public const REFRESH_MARGIN_SECONDS = 86400;

    /** How often to poll when the service does not say (RFC 8628, 3.2). */
    public const DEFAULT_INTERVAL_SECONDS = 5;

    /** How long a code holds when the service does not say. */
    private const DEFAULT_CODE_SECONDS = 900;

    /** How long an access token holds when the service does not say: refreshed a day early, it is never relied on long. */
    private const DEFAULT_TOKEN_SECONDS = 604800;

    /**
     * How long one refresh holds the connection: past the token request's
     * own time limit, so the lock never lapses while a refresh is still out
     * and a second worker cannot send the same refresh token. And how long
     * another worker waits for it.
     */
    private const REFRESH_LOCK_SECONDS = self::TIMEOUT_SECONDS + 30;

    private const REFRESH_WAIT_SECONDS = self::REFRESH_LOCK_SECONDS;

    public function __construct(private readonly TokenStore $tokenStore) {}

    /**
     * @throws ConnectionRejected|GrantUnavailable
     */
    public function begin(DeviceGrant $grant): DeviceAuthorization
    {
        $response = $this->form($grant, $grant->devicePath, ['client_id' => $grant->clientId, 'scope' => $grant->scope]);

        $this->throwIfDown($grant, $response);

        if ($this->refusesTheApp($response)) {
            throw new ConnectionRejected($grant->rejectedKey);
        }

        $answer = Payload::map($response->json());
        $deviceCode = Payload::string($answer, 'device_code');
        $userCode = Payload::string($answer, 'user_code');
        $verificationUri = Payload::string($answer, 'verification_uri') ?? $grant->verificationUri;

        if (! $response->successful() || $deviceCode === null || $userCode === null || $verificationUri === null) {
            throw new ConnectionRejected($grant->notStartedKey);
        }

        return new DeviceAuthorization(
            deviceCode: $deviceCode,
            userCode: $userCode,
            verificationUri: $verificationUri,
            verificationUriComplete: Payload::string($answer, 'verification_uri_complete'),
            expiresIn: Payload::int($answer, 'expires_in') ?? self::DEFAULT_CODE_SECONDS,
            interval: Payload::int($answer, 'interval') ?? self::DEFAULT_INTERVAL_SECONDS,
        );
    }

    /**
     * One poll: the tokens once approved, otherwise what to do next. A
     * refusal ends the attempt like an expired code; a service that never
     * reports one stays pending until the caller's deadline.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int}|DevicePoll
     *
     * @throws ConnectionRejected|GrantUnavailable
     */
    public function poll(DeviceGrant $grant, string $deviceCode): array|DevicePoll
    {
        $response = $this->form($grant, $grant->tokenPath, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $grant->clientId,
            'device_code' => $deviceCode,
        ]);

        if ($response->successful()) {
            return $this->tokens($grant, $response, null);
        }

        // Down for a moment is not "the code expired": the next poll asks again.
        $this->throwIfDown($grant, $response);

        if ($this->refusesTheApp($response)) {
            throw new ConnectionRejected($grant->rejectedKey);
        }

        return match ($this->error($response)) {
            OAuthError::AuthorizationPending => DevicePoll::Pending,
            OAuthError::SlowDown => DevicePoll::SlowDown,
            default => DevicePoll::Expired,
        };
    }

    /**
     * One request with the connection's access token, refreshed when due
     * or when the service answers 401; a second 401 means the grant is gone.
     *
     * @param  Closure(string): Response  $send  the request, given the token to send
     *
     * @throws ConnectionExpired|GrantUnavailable
     */
    public function call(DeviceGrant $grant, ServiceConnection $connection, Closure $send): Response
    {
        $token = $this->freshToken($grant, $connection);
        $response = $send($token);

        if ($response->status() === 401) {
            $response = $send($this->tokenAfter401($grant, $connection, $token));
        }

        if ($response->status() === 401) {
            throw new ConnectionExpired($grant->expiredKey);
        }

        return $response;
    }

    /**
     * Ends the grant at the service. It answers 200 whatever happened
     * (RFC 7009), so there is nothing to check.
     *
     * @throws GrantUnavailable
     */
    public function revoke(DeviceGrant $grant, ServiceConnection $connection): void
    {
        $token = $connection->credential('refresh_token') ?? $connection->credential('access_token');

        if ($token !== null) {
            $this->form($grant, $grant->revokePath, ['client_id' => $grant->clientId, 'token' => $token]);
        }
    }

    /** The lock one refresh of the connection holds. */
    public static function refreshLockName(ServiceConnection $connection): string
    {
        return "connection-refresh:{$connection->id}";
    }

    private function freshToken(DeviceGrant $grant, ServiceConnection $connection): string
    {
        $expiresAt = (int) ($connection->credential('expires_at') ?? 0);

        if ($expiresAt - now()->getTimestamp() > self::REFRESH_MARGIN_SECONDS) {
            return (string) $connection->credential('access_token');
        }

        return $this->refresh($grant, $connection, (string) $connection->credential('access_token'));
    }

    /**
     * A 401 looks the same whether the token expired or another worker
     * refreshed the grant. The stored token tells them apart: if it moved
     * on, use it; only if not, refresh.
     */
    private function tokenAfter401(DeviceGrant $grant, ServiceConnection $connection, string $rejected): string
    {
        $stored = $this->tokenStore->storedAccessToken($connection);

        return $stored !== $rejected ? $stored : $this->refresh($grant, $connection, $rejected);
    }

    private function refresh(DeviceGrant $grant, ServiceConnection $connection, string $stale): string
    {
        try {
            $token = Cache::lock(self::refreshLockName($connection), self::REFRESH_LOCK_SECONDS)->block(self::REFRESH_WAIT_SECONDS, function () use ($grant, $connection, $stale): string {
                $stored = $this->tokenStore->storedAccessToken($connection);

                if ($stored !== $stale) {
                    return $stored;
                }

                $refreshToken = (string) $connection->credential('refresh_token');
                $response = $this->form($grant, $grant->tokenPath, [
                    'grant_type' => 'refresh_token',
                    'client_id' => $grant->clientId,
                    'refresh_token' => $refreshToken,
                ]);

                if ($response->clientError()) {
                    throw new ConnectionExpired($grant->expiredKey);
                }

                if (! $response->successful()) {
                    throw new GrantUnavailable('import.service_answered_token_refresh', ['service' => $grant->service, 'status' => $response->status()]);
                }

                $tokens = $this->tokens($grant, $response, $refreshToken);

                $this->tokenStore->save($connection, $tokens);

                return $tokens['access_token'];
            });
        } catch (LockTimeoutException) {
            // Another worker's refresh is still out: its token will be there next time.
            throw new GrantUnavailable('import.service_token_refresh_under_way', ['service' => $grant->service]);
        }

        // The lock hands back whatever the closure returned.
        return is_string($token) ? $token : throw new LogicException('A token refresh ends in a token.');
    }

    /**
     * The service does not know the instance's app, or does not let it use
     * this grant: only the operator can change that.
     */
    private function refusesTheApp(Response $response): bool
    {
        return $response->status() === 401
            || in_array($this->error($response), [OAuthError::InvalidClient, OAuthError::UnauthorizedClient], true);
    }

    private function error(Response $response): ?OAuthError
    {
        return Payload::enum(Payload::map($response->json()), 'error', OAuthError::class);
    }

    /**
     * The tokens of a successful answer. A refresh answer may leave the
     * refresh token out (RFC 6749, 6): the one just used then stays.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int}
     *
     * @throws GrantUnavailable
     */
    private function tokens(DeviceGrant $grant, Response $response, ?string $usedRefreshToken): array
    {
        $answer = Payload::map($response->json());
        $access = Payload::string($answer, 'access_token');
        $refresh = Payload::string($answer, 'refresh_token') ?? $usedRefreshToken;

        if ($access === null || $refresh === null) {
            throw new GrantUnavailable('import.service_gave_no_token', ['service' => $grant->service]);
        }

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_at' => now()->addSeconds(Payload::int($answer, 'expires_in') ?? self::DEFAULT_TOKEN_SECONDS)->getTimestamp(),
        ];
    }

    /**
     * @throws GrantUnavailable
     */
    private function throwIfDown(DeviceGrant $grant, Response $response): void
    {
        if (HttpRetry::isOutage($response)) {
            throw new GrantUnavailable('import.service_answered_try_later', ['service' => $grant->service, 'status' => $response->status()]);
        }
    }

    /**
     * @param  array<string, string>  $form
     *
     * @throws GrantUnavailable
     */
    private function form(DeviceGrant $grant, string $path, array $form): Response
    {
        try {
            return Http::baseUrl(rtrim($grant->baseUrl, '/'))
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->withUserAgent(config()->string('tracker.http.user_agent'))
                ->asForm()
                ->post($path, $form);
        } catch (ConnectionException $e) {
            throw new GrantUnavailable('import.service_unreachable', ['service' => $grant->service, 'reason' => ConnectionFailure::reason($e)]);
        }
    }
}

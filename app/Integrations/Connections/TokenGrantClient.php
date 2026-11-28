<?php

namespace App\Integrations\Connections;

use App\Integrations\Connections\Data\TokenGrant;
use App\Integrations\ServiceHttpClient;
use App\Models\ServiceConnection;
use App\Support\Payload;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * A connection's tokens, kept fresh the same way whichever flow made the
 * grant (a device code, a trip through the service's site, a sign-in form)
 * and whichever dialect the service's token endpoint speaks
 * ({@see TokenRequestStyle}).
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
class TokenGrantClient
{
    private const TIMEOUT_SECONDS = 60;

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

    public function __construct(private readonly TokenStore $tokenStore, private readonly ServiceHttpClient $http) {}

    /**
     * One request with the connection's access token, refreshed when due
     * or when the service answers 401; a second 401 means the grant is gone.
     *
     * @param  Closure(string): Response  $send  the request, given the token to send
     *
     * @throws ConnectionExpired|GrantUnavailable
     */
    public function call(TokenGrant $grant, ServiceConnection $connection, Closure $send): Response
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
     * Ends the grant at the service. An OAuth service answers 200 whatever
     * happened (RFC 7009), so there is nothing to check; a Supabase session
     * is ended with its own access token, refreshed first when it lapsed.
     *
     * @throws GrantUnavailable
     */
    public function revoke(TokenGrant $grant, ServiceConnection $connection): void
    {
        match ($grant->style) {
            TokenRequestStyle::OAuthForm => $this->revokeGrant($grant, $connection),
            TokenRequestStyle::SupabaseJson => $this->endSession($grant, $connection),
        };
    }

    /** @throws GrantUnavailable */
    private function revokeGrant(TokenGrant $grant, ServiceConnection $connection): void
    {
        $token = $connection->credential('refresh_token') ?? $connection->credential('access_token');

        if ($token !== null) {
            $this->post($grant, $grant->revokePath, ['token' => $token]);
        }
    }

    /** @throws GrantUnavailable */
    private function endSession(TokenGrant $grant, ServiceConnection $connection): void
    {
        try {
            $this->call($grant, $connection, fn (string $token): Response => $this->http->reach(
                $grant->service,
                GrantUnavailable::class,
                fn (): Response => $this->signed($grant)->withToken($token)->post($grant->revokePath),
            ));
        } catch (ConnectionExpired) {
            // A session the service already let go of has nothing left to end.
        }
    }

    /** The lock one refresh of the connection holds. */
    public static function refreshLockName(ServiceConnection $connection): string
    {
        return "connection-refresh:{$connection->id}";
    }

    private function freshToken(TokenGrant $grant, ServiceConnection $connection): string
    {
        $expiresAt = (int) ($connection->credential('expires_at') ?? 0);

        if ($expiresAt - now()->getTimestamp() > $grant->refreshMarginSeconds) {
            return (string) $connection->credential('access_token');
        }

        return $this->refresh($grant, $connection, (string) $connection->credential('access_token'));
    }

    /**
     * A 401 looks the same whether the token expired or another worker
     * refreshed the grant. The stored token tells them apart: if it moved
     * on, use it; only if not, refresh.
     */
    private function tokenAfter401(TokenGrant $grant, ServiceConnection $connection, string $rejected): string
    {
        $stored = $this->tokenStore->storedAccessToken($connection);

        return $stored !== $rejected ? $stored : $this->refresh($grant, $connection, $rejected);
    }

    private function refresh(TokenGrant $grant, ServiceConnection $connection, string $stale): string
    {
        try {
            $token = Cache::lock(self::refreshLockName($connection), self::REFRESH_LOCK_SECONDS)->block(self::REFRESH_WAIT_SECONDS, function () use ($grant, $connection, $stale): string {
                $stored = $this->tokenStore->storedAccessToken($connection);

                if ($stored !== $stale) {
                    return $stored;
                }

                $refreshToken = (string) $connection->credential('refresh_token');
                $response = $this->post($grant, $grant->tokenPath, match ($grant->style) {
                    TokenRequestStyle::OAuthForm => ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken],
                    // The grant type rides in the token path's own query.
                    TokenRequestStyle::SupabaseJson => ['refresh_token' => $refreshToken],
                });

                // A 429 is busy, not refused: the refresh token was not spent.
                if ($response->clientError() && $response->status() !== 429) {
                    throw new ConnectionExpired($grant->expiredKey);
                }

                if (! $response->successful()) {
                    throw new GrantUnavailable('import.service_answered_token_refresh', ['service' => $grant->service, 'status' => $response->status()]);
                }

                $tokens = $this->readTokens($grant, $response, $refreshToken);

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
     * A request to the service's token endpoints, signed by the app as the
     * service's dialect has it.
     *
     * @param  array<string, string>  $fields
     *
     * @throws GrantUnavailable
     */
    public function post(TokenGrant $grant, string $path, array $fields): Response
    {
        return $this->http->reach($grant->service, GrantUnavailable::class, fn (): Response => match ($grant->style) {
            TokenRequestStyle::OAuthForm => $this->signed($grant)->asForm()->post($path, [
                ...$fields,
                'client_id' => $grant->clientId,
                ...($grant->clientSecret === null ? [] : ['client_secret' => $grant->clientSecret]),
            ]),
            TokenRequestStyle::SupabaseJson => $this->signed($grant)->asJson()->post($path, $fields),
        });
    }

    /**
     * The request every token call starts from, held to a user's own server
     * when the grant lives on one; a Supabase project also wants its key on
     * every one.
     */
    private function signed(TokenGrant $grant): PendingRequest
    {
        $request = $this->http->request($grant->baseUrl, self::TIMEOUT_SECONDS);
        $request = $grant->pinned === null ? $request : $grant->pinned->hold($request);

        return match ($grant->style) {
            TokenRequestStyle::OAuthForm => $request,
            TokenRequestStyle::SupabaseJson => $request->withHeaders(['apikey' => $grant->clientId]),
        };
    }

    /**
     * The tokens of a successful answer from the token endpoint. A refresh
     * answer may leave the refresh token out (RFC 6749, 6): the one just used
     * then stays.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int}
     *
     * @throws GrantUnavailable
     */
    public function readTokens(TokenGrant $grant, Response $response, ?string $usedRefreshToken): array
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
}

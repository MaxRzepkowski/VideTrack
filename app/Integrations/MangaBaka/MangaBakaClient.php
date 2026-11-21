<?php

namespace App\Integrations\MangaBaka;

use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Data\TokenGrant;
use App\Integrations\Connections\GrantUnavailable;
use App\Integrations\Connections\OAuthError;
use App\Integrations\Connections\TokenGrantClient;
use App\Models\ServiceConnection;
use App\Support\ConnectionFailure;
use App\Support\HttpRetry;
use App\Support\Payload;
use App\Support\ServiceBusy;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A MangaBaka library read with the token its owner granted the instance
 * (mangabaka.org/developers, OAuth guide and API schema, read 2026-11-21):
 *
 * - the grant comes from the OAuth authorization code flow of a web app the
 *   operator registered, with PKCE (S256) and the app's secret, asking to
 *   read the profile and the library; `openid` is required, or the API
 *   refuses the token;
 * - access tokens live a week; refresh tokens rotate on every use and one
 *   spent twice revokes every token of the app for that user, which
 *   TokenGrantClient's refresh is built for;
 * - `/v2/my/library` pages by `page` up to a hundred entries, until
 *   `pagination.next` is null; `/my/*` allows 180 requests a minute, and a
 *   429 is waited out by the run. Only a dropped connection or a 5xx is
 *   asked again at once.
 */
class MangaBakaClient
{
    public const PAGE_SIZE = 100;

    private const SCOPE = 'openid profile library.read offline_access';

    private const TIMEOUT_SECONDS = 30;

    private const RETRIES = 3;

    private const FIRST_RETRY_MS = 1000;

    /** A 429 without a Retry-After waits out a minute's allowance. */
    private const DEFAULT_WAIT_SECONDS = 60;

    public function __construct(private readonly TokenGrantClient $tokens) {}

    /** Where the user approves the app, carrying the attempt's state and the PKCE challenge. */
    public function authorizationUrl(string $callbackUrl, string $state, string $challenge): string
    {
        return $this->authUrl().'/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config()->string('tracker.mangabaka.client_id'),
            'redirect_uri' => $callbackUrl,
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * The tokens an approved code stands for.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int}
     *
     * @throws ConnectionRejected|GrantUnavailable
     */
    public function exchange(string $code, string $verifier, string $callbackUrl): array
    {
        $response = $this->tokens->post($this->grant(), '/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $callbackUrl,
            'code_verifier' => $verifier,
        ]);

        if (HttpRetry::isOutage($response)) {
            throw new GrantUnavailable('import.service_answered_try_later', ['service' => MangaBakaService::NAME, 'status' => $response->status()]);
        }

        if (Payload::enum(Payload::map($response->json()), 'error', OAuthError::class) === OAuthError::InvalidClient) {
            throw new ConnectionRejected('import.mangabaka_did_not_accept_this_instance');
        }

        if (! $response->successful()) {
            throw new ConnectionRejected('import.mangabaka_connection_was_not_approved');
        }

        return $this->tokens->readTokens($this->grant(), $response, null);
    }

    /**
     * The account's name, for the card — read with a token straight from the
     * code exchange. Only a label: a dropped connection leaves it unknown.
     */
    public function username(string $accessToken): ?string
    {
        try {
            $response = $this->http()->withToken($accessToken)->get($this->authUrl().'/userinfo');
        } catch (ConnectionException) {
            return null;
        }

        $user = $response->successful() ? Payload::map($response->json()) : [];

        return Payload::string($user, 'preferred_username') ?? Payload::string($user, 'name');
    }

    /**
     * @throws GrantUnavailable
     */
    public function revoke(ServiceConnection $connection): void
    {
        $this->tokens->revoke($this->grant(), $connection);
    }

    /**
     * Every page of the library, each entry with its series and lists.
     *
     * @return Generator<int, list<array<array-key, mixed>>>
     *
     * @throws MangaBakaUnavailable|MangaBakaRejected|ServiceBusy|ConnectionExpired|GrantUnavailable
     */
    public function libraryPages(ServiceConnection $connection): Generator
    {
        $page = 1;

        do {
            // In the order entries were added, so a save during the run moves no entry between pages.
            $query = ['page' => (string) $page++, 'limit' => (string) self::PAGE_SIZE, 'sort_by' => 'created_at_asc'];
            $response = $this->tokens->call($this->grant(), $connection, fn (string $token): Response => $this->send($token, '/v2/my/library', $query));

            if ($response->status() === 429) {
                throw new ServiceBusy('import.service_asked_to_wait', HttpRetry::retryAfter($response, self::DEFAULT_WAIT_SECONDS), ['service' => MangaBakaService::NAME]);
            }

            if (HttpRetry::isOutage($response)) {
                throw new MangaBakaUnavailable('import.service_answered_try_later', ['service' => MangaBakaService::NAME, 'status' => $response->status()]);
            }

            // Answered and refused (a 403 is a token without the library scope):
            // the same request would be refused again.
            if (! $response->successful()) {
                throw new MangaBakaRejected('import.service_answered_to', ['service' => MangaBakaService::NAME, 'status' => $response->status(), 'path' => '/v2/my/library']);
            }

            $body = Payload::map($response->json());

            yield Payload::list($body['data'] ?? null);
        } while (Payload::string(Payload::map($body['pagination'] ?? null), 'next') !== null);
    }

    /** Where the app's tokens are refreshed and revoked: a web app, so signed with its secret. */
    public function grant(): TokenGrant
    {
        return new TokenGrant(
            service: MangaBakaService::NAME,
            baseUrl: $this->authUrl(),
            clientId: config()->string('tracker.mangabaka.client_id'),
            clientSecret: config()->string('tracker.mangabaka.client_secret'),
            tokenPath: '/token',
            revokePath: '/revoke',
            expiredKey: 'import.mangabaka_stopped_accepting_this_connection',
        );
    }

    /**
     * @param  array<string, string>  $query
     */
    private function send(string $token, string $path, array $query): Response
    {
        try {
            return $this->http()
                ->baseUrl(rtrim(config()->string('tracker.mangabaka.api_url'), '/'))
                ->withToken($token)
                ->retry(self::RETRIES, fn (int $attempt): int => self::FIRST_RETRY_MS * 2 ** ($attempt - 1), HttpRetry::isTransient(...), throw: false)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new MangaBakaUnavailable('import.service_unreachable', ['service' => MangaBakaService::NAME, 'reason' => ConnectionFailure::reason($e)]);
        }
    }

    private function http(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->withUserAgent(config()->string('tracker.http.user_agent'));
    }

    private function authUrl(): string
    {
        return rtrim(config()->string('tracker.mangabaka.auth_url'), '/');
    }
}

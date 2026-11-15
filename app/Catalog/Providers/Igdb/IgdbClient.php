<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\ProviderCacheContext;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Catalog\Providers\Concerns\MakesCachedRequests;
use App\Catalog\Providers\Concerns\SendsHttpRequests;
use App\Catalog\Providers\Concerns\ThrottlesRequests;
use App\Support\Payload;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/**
 * Thin HTTP client for the IGDB v4 API (Apicalypse queries via POST). All
 * requests go through the response cache — the Twitch credentials never
 * reach the browser — and a client-side rate limit under IGDB's 4 req/s.
 * The app access token is fetched with the client_credentials grant and
 * cached until shortly before it expires.
 */
class IgdbClient
{
    use MakesCachedRequests;
    use SendsHttpRequests;
    use ThrottlesRequests;

    private const RATE_LIMIT_PER_SECOND = 3;

    private const TOKEN_CACHE_KEY = 'igdb:token';

    private const MULTIQUERY = 'multiquery';

    /** The most queries IGDB runs in one multiquery. */
    private const MULTIQUERY_LIMIT = 10;

    public function __construct(private readonly ProviderCacheContext $cacheContext) {}

    /**
     * Cached query. TTL in seconds; keys are derived from endpoint + body so
     * the same request within the TTL never hits IGDB twice.
     *
     * @param  bool  $requireResults  when true, an empty result (a transient
     *                                hiccup on a listing/detail query) is NOT
     *                                cached, so the next request self-heals;
     *                                genuinely optional sub-resources pass false.
     * @return list<array<array-key, mixed>>
     */
    public function query(string $endpoint, string $body, int $ttl, bool $requireResults = false): array
    {
        $key = $this->cacheKey('igdb', $endpoint.'|'.$body);

        return Payload::list($this->cached(
            $key,
            $ttl,
            fn (): array => $this->fetch($endpoint, $body),
            $requireResults ? fn (mixed $data): bool => $data === [] : null,
        ));
    }

    /**
     * Several queries in one request (IGDB's multiquery): one call against
     * the rate limit for what would be several in a row, cached whole. An
     * answer missing one of the named results is a failure, not an empty
     * result — IGDB documents no partial answer, so none is guessed at.
     *
     * @param  non-empty-array<string, array{string, string}>  $queries  result name => [endpoint, body]
     * @param  string|null  $required  the result whose emptiness is a hiccup, as `requireResults` in {@see query()}: then nothing is cached
     * @return array<string, list<array<array-key, mixed>>> result name => rows
     */
    public function multiquery(array $queries, int $ttl, ?string $required = null): array
    {
        if (count($queries) > self::MULTIQUERY_LIMIT) {
            throw new LogicException('An IGDB multiquery runs at most '.self::MULTIQUERY_LIMIT.' queries.');
        }

        if ($required !== null && ! array_key_exists($required, $queries)) {
            throw new LogicException("An IGDB multiquery requires [{$required}], which it does not ask.");
        }

        $body = implode("\n", array_map(
            fn (string $name, array $query): string => "query {$query[0]} \"{$name}\" { {$query[1]} };",
            array_keys($queries),
            $queries,
        ));

        $answer = $this->cached(
            $this->cacheKey('igdb', self::MULTIQUERY.'|'.$body),
            $ttl,
            fn (): array => $this->results(array_keys($queries), $this->fetch(self::MULTIQUERY, $body)),
            $required === null ? null : fn (mixed $data): bool => Payload::list(Payload::map($data)[$required] ?? null) === [],
        );

        $results = [];

        foreach (array_keys($queries) as $name) {
            $results[$name] = Payload::list(Payload::map($answer)[$name] ?? null);
        }

        return $results;
    }

    /**
     * Row count for a filter — IGDB serves it on the /count sub-resource.
     */
    public function count(string $endpoint, string $where, int $ttl): int
    {
        $key = $this->cacheKey('igdb', $endpoint.'/count|'.$where);

        $count = $this->cached($key, $ttl, function () use ($endpoint, $where): int {
            $this->throttle('igdb-api', self::RATE_LIMIT_PER_SECOND);

            return Payload::int($this->send("{$endpoint}/count", $where), 'count') ?? 0;
        });

        return is_int($count) ? $count : 0;
    }

    /**
     * A multiquery answer by result name: `[{name, result}, …]`.
     *
     * @param  list<string>  $names
     * @param  list<array<array-key, mixed>>  $answer
     * @return array<string, list<array<array-key, mixed>>>
     */
    private function results(array $names, array $answer): array
    {
        $byName = [];

        foreach ($answer as $entry) {
            $name = Payload::string($entry, 'name');

            if ($name !== null) {
                $byName[$name] = Payload::list($entry['result'] ?? null);
            }
        }

        foreach ($names as $name) {
            if (! array_key_exists($name, $byName)) {
                throw new ProviderException("IGDB multiquery answered without [{$name}].");
            }
        }

        return $byName;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function fetch(string $endpoint, string $body): array
    {
        $this->throttle('igdb-api', self::RATE_LIMIT_PER_SECOND);

        return Payload::list($this->send($endpoint, $body));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function send(string $endpoint, string $body): array
    {
        $response = $this->guardConnection('IGDB', fn () => $this->httpClient(config()->string('tracker.igdb.base_url'))
            ->withHeaders(['Client-ID' => $this->clientId()])
            ->withToken($this->token())
            ->withBody($body, 'text/plain')
            ->post($endpoint));

        // 401 = the cached token was revoked ahead of its expiry; drop it so
        // the next request re-authenticates — a refusal that asking again cures.
        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);

            throw new ProviderException("IGDB request [{$endpoint}] failed with status 401.");
        }

        if ($response->failed()) {
            throw $this->failure($response, "IGDB request [{$endpoint}]");
        }

        return Payload::map($response->json());
    }

    private function token(): string
    {
        $cached = $this->cachedToken();

        if ($cached !== null) {
            return $cached;
        }

        $response = $this->guardConnection('Twitch OAuth', fn () => $this->httpClient()->asForm()
            ->post(config()->string('tracker.igdb.oauth_url'), [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'grant_type' => 'client_credentials',
            ]));

        $answer = Payload::map($response->json());
        $token = Payload::text($answer, 'access_token');

        // Twitch refusing the client id or secret is a wrong key: asking again will not help.
        if ($response->clientError() && $response->status() !== 429) {
            throw new ProviderRejected("IGDB authentication failed with status {$response->status()}. Check IGDB_CLIENT_ID / IGDB_CLIENT_SECRET.");
        }

        if ($response->failed() || $token === '') {
            throw new ProviderException("IGDB authentication failed with status {$response->status()}. Check IGDB_CLIENT_ID / IGDB_CLIENT_SECRET.");
        }

        // Tokens live ~60 days; renew a day early so no request ever runs
        // into an expiry mid-flight.
        $ttl = max(60, (Payload::int($answer, 'expires_in') ?? 0) - 60 * 60 * 24);

        // A credential, so the cache store holds it encrypted like any other.
        Cache::put(self::TOKEN_CACHE_KEY, Crypt::encryptString($token), $ttl);

        return $token;
    }

    private function cachedToken(): ?string
    {
        $stored = Cache::get(self::TOKEN_CACHE_KEY);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        // One that does not decrypt (another APP_KEY, or stored in clear) is no token: ask for a fresh one.
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    private function clientId(): string
    {
        return $this->credential('tracker.igdb.client_id', 'IGDB_CLIENT_ID');
    }

    private function clientSecret(): string
    {
        return $this->credential('tracker.igdb.client_secret', 'IGDB_CLIENT_SECRET');
    }
}

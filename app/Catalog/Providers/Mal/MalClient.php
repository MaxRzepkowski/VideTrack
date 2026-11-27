<?php

namespace App\Catalog\Providers\Mal;

use App\Catalog\ProviderCacheContext;
use App\Catalog\ProviderHealth;
use App\Catalog\Providers\Concerns\MakesCachedRequests;
use App\Catalog\Providers\Concerns\SendsHttpRequests;
use App\Catalog\Providers\Concerns\ThrottlesRequests;
use App\Support\Payload;

/**
 * Thin HTTP client for the MyAnimeList API v2. Public catalogue data only
 * needs the client id in the X-MAL-CLIENT-ID header — no OAuth. All requests
 * go through the response cache and a conservative client-side rate limit
 * (MAL publishes no hard number; 55 req/min stays well clear of trouble).
 */
class MalClient
{
    /** The setting the operator writes the key under. */
    public const CLIENT_ID_VARIABLE = 'MAL_CLIENT_ID';

    use MakesCachedRequests;
    use SendsHttpRequests;
    use ThrottlesRequests;

    /**
     * MAL's budget for this instance's client id, shared with the anime-list
     * import (MalListClient), which throttles on the same limiter.
     */
    public const RATE_LIMITER = 'mal-api';

    public const RATE_LIMIT_PER_MINUTE = 55;

    /**
     * Listings ask MAL for adult titles too: what MAL hides by default is its
     * own call, so the catalog's rule (MangaNsfw::allowedAt) decides instead.
     */
    public const ALL_RATINGS = ['nsfw' => 'true'];

    public function __construct(
        private readonly ProviderCacheContext $cacheContext,
        private readonly ProviderHealth $health,
    ) {}

    /**
     * Cached GET request. TTL in seconds; keys are derived from the path +
     * query so the same request within the TTL never hits MAL twice.
     *
     * @param  array<string, string|int>  $query
     * @param  string|null  $requireKey  key (e.g. 'data', 'id') whose emptiness
     *                                   marks a degraded reply that must NOT be
     *                                   cached, so the next request self-heals.
     * @return array<array-key, mixed>
     */
    public function get(string $path, array $query, int $ttl, ?string $requireKey = null): array
    {
        $key = $this->cacheKey('mal', $path, $query);

        return Payload::map($this->cached(
            $key,
            $ttl,
            fn (): array => $this->fetch($path, $query),
            $requireKey === null ? null : fn (mixed $data): bool => ! is_array($data) || empty($data[$requireKey]),
        ));
    }

    /**
     * One request past the cache, as a probe of whether MyAnimeList answers and takes the client id.
     *
     * @param  array<string, string|int>  $query
     */
    public function probe(string $path, array $query): void
    {
        $this->fetch($path, $query);
    }

    /** Seconds an answer of this kind (search, details, listing) is kept. */
    public function ttl(string $kind): int
    {
        return config()->integer("tracker.mal.cache.{$kind}");
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<array-key, mixed>
     */
    private function fetch(string $path, array $query): array
    {
        $this->throttle(self::RATE_LIMITER, self::RATE_LIMIT_PER_MINUTE, 60);

        return $this->health->watch(MalProvider::CODE, fn (): array => $this->send($path, $query));
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<array-key, mixed>
     */
    private function send(string $path, array $query): array
    {
        $response = $this->guardConnection('MyAnimeList', fn () => $this->httpClient(config()->string('tracker.mal.base_url'))
            ->withHeaders(['X-MAL-CLIENT-ID' => $this->clientId()])
            ->get($path, $query));

        // MAL answers 404 for unknown ids with a JSON error body; an empty
        // payload lets the provider raise ItemNotFoundException instead of
        // a generic provider failure.
        if ($response->status() === 404) {
            return [];
        }

        if ($response->failed()) {
            throw $this->failure($response, "MyAnimeList request [{$path}]");
        }

        return Payload::map($response->json());
    }

    private function clientId(): string
    {
        return $this->credential('tracker.mal.client_id', self::CLIENT_ID_VARIABLE);
    }
}

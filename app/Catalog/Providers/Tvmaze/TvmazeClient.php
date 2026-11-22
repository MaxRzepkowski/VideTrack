<?php

namespace App\Catalog\Providers\Tvmaze;

use App\Catalog\ProviderCacheContext;
use App\Catalog\Providers\Concerns\MakesCachedRequests;
use App\Catalog\Providers\Concerns\SendsHttpRequests;
use App\Catalog\Providers\Concerns\ThrottlesRequests;

/**
 * Thin HTTP client for the free, keyless TVmaze API (~20 req / 10 s). Used
 * only by the background schedule-enrichment job — never on a page render.
 * Responses are cached; a missing show / episode list returns null rather
 * than throwing, so enrichment degrades quietly.
 */
class TvmazeClient
{
    use MakesCachedRequests, SendsHttpRequests, ThrottlesRequests;

    // TVmaze allows ~20 requests per 10 seconds; stay comfortably under it.
    private const RATE_LIMIT_PER_10S = 18;

    public function __construct(private readonly ProviderCacheContext $cacheContext) {}

    /**
     * Cached GET. Returns the decoded body, or null on 404 (unknown show /
     * no episodes) — the 404 is cached too, so an unknown show is not asked
     * about on every run. TTL comes from the schedule cache config.
     *
     * @param  array<string, string|int>  $params
     * @return array<int|string, mixed>|null
     */
    public function get(string $path, array $params = []): ?array
    {
        $answer = $this->cached(
            $this->cacheKey('tvmaze', $path, $params),
            config()->integer('tracker.tvmaze.cache.schedule'),
            fn (): ?array => $this->fetch($path, $params),
        );

        return is_array($answer) ? $answer : null;
    }

    /**
     * Cache-only read: returns a previously cached body, or null on a miss —
     * never hits TVmaze (so it never throttles or blocks). Page renders use
     * this; the background job's live get() warms the cache for them.
     *
     * @param  array<string, string|int>  $params
     * @return array<int|string, mixed>|null
     */
    public function getCached(string $path, array $params = []): ?array
    {
        $answer = $this->peekCached($this->cacheKey('tvmaze', $path, $params));

        return is_array($answer) ? $answer : null;
    }

    /**
     * @param  array<string, string|int>  $params
     * @return array<int|string, mixed>|null
     */
    private function fetch(string $path, array $params): ?array
    {
        // The window is ten seconds, so the wait may need all of it and then some.
        $this->throttle('tvmaze-api', self::RATE_LIMIT_PER_10S, 10, maxWaitSeconds: 15.0);

        $response = $this->guardConnection('TVmaze', fn () => $this->httpClient(config()->string('tracker.tvmaze.base_url'))
            ->get($path, $params));

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw $this->failure($response, "TVmaze request [{$path}]");
        }

        $answer = $response->json();

        return is_array($answer) ? $answer : null;
    }
}

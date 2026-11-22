<?php

namespace App\Catalog\Providers\ComicVine;

use App\Catalog\ProviderBusy;
use App\Catalog\ProviderCacheContext;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Catalog\Providers\Concerns\MakesCachedRequests;
use App\Catalog\Providers\Concerns\SendsHttpRequests;
use App\Catalog\Providers\Concerns\ThrottlesRequests;
use App\Support\Payload;

/**
 * Thin HTTP client for the ComicVine API. All requests go through the
 * response cache — the api key never reaches the browser. ComicVine's quota
 * is tight (200 requests per resource per hour) and it detects request
 * velocity, so the client paces to 1 req/s and FAILS FAST once the local
 * hourly budget is spent instead of queueing a wait of minutes.
 */
class ComicVineClient
{
    use MakesCachedRequests;
    use SendsHttpRequests;
    use ThrottlesRequests;

    /** Invalid API key, malformed URL, jsonp without a callback, bad filter, subscriber-only resource. */
    private const REFUSALS = [100, 102, 103, 104, 105];

    private const RATE_LIMIT_PER_SECOND = 1;

    public const RATE_LIMIT_PER_HOUR = 180;

    /** ComicVine counts its quota per resource over an hour. */
    public const BUDGET_WINDOW_SECONDS = 3600;

    public const HOURLY_BUDGET = 'comicvine-api-hour';

    /** "Rate limit exceeded": the hour's quota is gone at ComicVine's end. */
    private const RATE_LIMITED = 107;

    public function __construct(private readonly ProviderCacheContext $cacheContext) {}

    /**
     * Cached GET request. TTL in seconds; keys are derived from the path +
     * query so the same request within the TTL never hits ComicVine twice.
     *
     * @param  array<string, string|int>  $query
     * @param  bool  $requireResults  when true, an empty `results` (a transient
     *                                hiccup) is NOT cached, so the next request
     *                                self-heals, and a not-found is kept only
     *                                for the short not-found window.
     * @return array<array-key, mixed> the response's `results` member
     */
    public function get(string $path, array $query, int $ttl, bool $requireResults = false): array
    {
        $key = $this->cacheKey('comicvine', $path, $query);
        $asked = false;

        $answer = $this->cached(
            $key,
            $ttl,
            function () use ($path, $query, &$asked): ?array {
                $asked = true;

                return $this->fetch($path, $query);
            },
            $requireResults ? fn (mixed $data): bool => $data === [] || $data === null : null,
        );

        // "Not found" is an answer, not a hiccup: remembered briefly, so a
        // vanished volume does not spend the hourly budget on every refresh.
        if ($answer === null && $requireResults && $asked) {
            $this->storeCached($key, null, config()->integer('tracker.comicvine.cache.not_found'));
        }

        return Payload::map($answer);
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<array-key, mixed>|null the `results` member; null when ComicVine reports the object not found
     */
    private function fetch(string $path, array $query): ?array
    {
        $this->guardBudget();

        // ComicVine turns away requests without a client of their own.
        $response = $this->guardConnection('ComicVine', fn () => $this->httpClient(config()->string('tracker.comicvine.base_url'))
            ->withUserAgent(config()->string('tracker.http.user_agent'))
            ->get($path, [
                ...$query,
                'api_key' => $this->apiKey(),
                'format' => 'json',
            ]));

        if ($response->failed()) {
            throw $this->failure($response, "ComicVine request [{$path}]");
        }

        // ComicVine transports errors in a 200 body: status_code 1 = OK,
        // 101 = object not found. The codes its documentation lists for a request it
        // will never take — a wrong key, a malformed URL or filter, a
        // subscriber-only resource — are refusals; the rate limit is waited
        // out for its hour; anything else may pass on its own.
        $answer = Payload::map($response->json());
        $status = Payload::int($answer, 'status_code') ?? 0;

        if ($status === 101) {
            return null;
        }

        if ($status === self::RATE_LIMITED) {
            throw new ProviderBusy("ComicVine request [{$path}] hit the rate limit.", self::BUDGET_WINDOW_SECONDS);
        }

        if (in_array($status, self::REFUSALS, true)) {
            throw new ProviderRejected("ComicVine refused request [{$path}] with status_code {$status}.");
        }

        if ($status !== 1) {
            throw new ProviderException("ComicVine request [{$path}] failed with status_code {$status}.");
        }

        return Payload::map($answer['results'] ?? null);
    }

    private function apiKey(): string
    {
        return $this->credential('tracker.comicvine.api_key', 'COMICVINE_API_KEY');
    }

    private function guardBudget(): void
    {
        // The hourly budget fails fast: waiting out an exhausted hour inside
        // a web request is never acceptable, and search degrades gracefully.
        $this->throttle(self::HOURLY_BUDGET, self::RATE_LIMIT_PER_HOUR, self::BUDGET_WINDOW_SECONDS, maxWaitSeconds: 0.0);

        // Velocity pacing spreads bursts across seconds.
        $this->throttle('comicvine-api', self::RATE_LIMIT_PER_SECOND, 1);
    }
}

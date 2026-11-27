<?php

namespace App\Catalog\Providers\Hardcover;

use App\Catalog\ProviderCacheContext;
use App\Catalog\ProviderException;
use App\Catalog\ProviderHealth;
use App\Catalog\Providers\Concerns\MakesCachedRequests;
use App\Catalog\Providers\Concerns\SendsHttpRequests;
use App\Catalog\Providers\Concerns\ThrottlesRequests;

/**
 * Thin HTTP client for the Hardcover GraphQL API. All requests go through
 * the response cache — the personal API key never reaches the browser —
 * and a client-side rate limit under Hardcover's 60 req/min.
 */
class HardcoverClient
{
    /** The setting the operator writes the key under. */
    public const KEY_VARIABLE = 'HARDCOVER_API_KEY';

    use MakesCachedRequests;
    use SendsHttpRequests;
    use ThrottlesRequests;

    private const RATE_LIMIT_PER_MINUTE = 55;

    public function __construct(
        private readonly ProviderCacheContext $cacheContext,
        private readonly ProviderHealth $health,
    ) {}

    /**
     * Cached GraphQL query. TTL in seconds; keys are derived from the query
     * + variables so the same request within the TTL never hits Hardcover
     * twice.
     *
     * @param  array<string, mixed>  $variables
     * @param  string|null  $requireKey  dot-path (e.g. 'search.results.hits')
     *                                   whose emptiness marks a degraded reply
     *                                   that must NOT be cached, so the next
     *                                   request self-heals; detail lookups pass
     *                                   null and always cache.
     * @return array<string, mixed> the response's `data` object
     */
    public function query(string $graphql, array $variables, int $ttl, ?string $requireKey = null): array
    {
        $key = $this->cacheKey('hardcover', $graphql, $variables);

        /** @var array<string, mixed> */
        return $this->cached(
            $key,
            $ttl,
            fn (): array => $this->fetch($graphql, $variables),
            $requireKey === null ? null : fn (mixed $data): bool => empty(data_get($data, $requireKey)),
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function fetch(string $graphql, array $variables): array
    {
        $this->throttle('hardcover-api', self::RATE_LIMIT_PER_MINUTE, 60);

        return $this->health->watch(HardcoverProvider::CODE, fn (): array => $this->send($graphql, $variables));
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function send(string $graphql, array $variables): array
    {
        $response = $this->guardConnection('Hardcover', fn () => $this->httpClient()
            ->withToken($this->apiKey())
            ->post(config()->string('tracker.hardcover.endpoint'), [
                'query' => $graphql,
                'variables' => (object) $variables,
            ]));

        if ($response->failed()) {
            throw $this->failure($response, 'Hardcover request');
        }

        // GraphQL transports errors in a 200 body.
        $error = $response->json('errors.0.message');

        if (is_string($error) && $error !== '') {
            throw new ProviderException("Hardcover request failed: {$error}");
        }

        /** @var array<string, mixed> */
        return (array) $response->json('data', []);
    }

    /**
     * One query past the cache, as a probe of whether Hardcover answers and takes the key.
     *
     * @param  array<string, mixed>  $variables
     */
    public function probe(string $graphql, array $variables): void
    {
        $this->fetch($graphql, $variables);
    }

    /** Hardcover's account page shows a key as a whole header; a key pasted with its "Bearer " is the same key. */
    public static function bareKey(string $key): string
    {
        return (string) preg_replace('/^Bearer\s+/i', '', trim($key));
    }

    private function apiKey(): string
    {
        return self::bareKey($this->credential('tracker.hardcover.api_key', self::KEY_VARIABLE));
    }
}

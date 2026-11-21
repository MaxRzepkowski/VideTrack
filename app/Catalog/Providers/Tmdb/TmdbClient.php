<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderCacheContext;
use App\Catalog\ProviderException;
use App\Catalog\Providers\Concerns\MakesCachedRequests;
use App\Catalog\Providers\Concerns\SendsHttpRequests;
use App\Catalog\Providers\Concerns\ThrottlesRequests;
use App\Support\Payload;
use Illuminate\Http\Client\PendingRequest;

/**
 * Thin HTTP client for the TMDB v3 API. All requests go through the response
 * cache (the API key never reaches the browser) and a client-side rate limit
 * that stays well under TMDB's ~50 req/s.
 */
class TmdbClient
{
    use MakesCachedRequests;
    use SendsHttpRequests;
    use ThrottlesRequests;

    /** A request path: plain segments of letters, digits, "_" and "-", never "." or a query. */
    private const PATH = '#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#';

    public const RATE_LIMIT_PER_SECOND = 30;

    public function __construct(private readonly ProviderCacheContext $cacheContext) {}

    /**
     * Cached GET. TTL in seconds; keys are derived from path + params so the
     * same request within the TTL never hits TMDB twice.
     *
     * @param  array<string, string|int|bool>  $params
     * @param  string|null  $requireKey  when set (e.g. 'results'), a response
     *                                   whose key is empty is NOT cached — a
     *                                   transient provider hiccup that returns
     *                                   an empty listing would otherwise freeze
     *                                   an empty page for the whole TTL. Detail
     *                                   responses pass null and always cache.
     * @return array<array-key, mixed>
     */
    public function get(string $path, array $params, int $ttl, ?string $requireKey = null): array
    {
        $key = $this->cacheKey('tmdb', $path, $params);

        return Payload::map($this->cached(
            $key,
            $ttl,
            // An object answer is never empty: `{}` is a degraded response, not a title.
            fn (): array => $this->fetch($path, $params) ?: throw new ProviderException("TMDB request [{$path}] answered an empty object."),
            $requireKey === null ? null : fn (mixed $data): bool => ! is_array($data) || empty($data[$requireKey]),
        ));
    }

    /**
     * Cached GET for TMDB configuration endpoints whose root JSON value is a
     * list. An empty list is treated as degraded and is therefore not cached.
     *
     * @param  array<string, string|int|bool>  $params
     * @return list<array<array-key, mixed>>
     */
    public function getList(string $path, array $params, int $ttl): array
    {
        $key = $this->cacheKey('tmdb', $path, $params);

        return Payload::list($this->cached(
            $key,
            $ttl,
            fn (): array => Payload::list($this->fetch($path, $params)),
            fn (mixed $data): bool => $data === [],
        ));
    }

    /**
     * Seed the canonical cache entry from an append_to_response sub-payload.
     * This lets a capability resolver read the child endpoint without turning
     * a sub-request that already rode along with details into another request.
     *
     * @param  array<string, string|int|bool>  $params
     * @param  array<array-key, mixed>  $data
     */
    public function prime(string $path, array $params, int $ttl, array $data): void
    {
        $this->storeCached($this->cacheKey('tmdb', $path, $params), $data, $ttl);
    }

    /**
     * @param  array<string, string|int|bool>  $params
     * @return array<array-key, mixed>
     */
    private function fetch(string $path, array $params): array
    {
        // Refs reach this path from webhook payloads and import files, not
        // only from validated routes: a ref that is not plain segments ("603/
        // ../account", "1399?x") would ask another endpoint with this
        // instance's key. Such a ref names no title.
        if (preg_match(self::PATH, $path) !== 1) {
            throw new ItemNotFoundException("Malformed TMDB path [{$path}].");
        }

        // Spread bursts (e.g. a cold locale-fallback cascade) across seconds.
        $this->throttle('tmdb-api', self::RATE_LIMIT_PER_SECOND);

        $response = $this->guardConnection('TMDB', fn () => $this->request()->get($path, $params));

        if ($response->status() === 404) {
            throw new ItemNotFoundException("TMDB returned 404 for [{$path}].");
        }

        if ($response->failed()) {
            throw $this->failure($response, "TMDB request [{$path}]");
        }

        $data = $response->json();

        // A 200 without JSON (a cut-off body, a proxy's page) is not an
        // answer: cached, it would stand in for the title for a day.
        if (! is_array($data)) {
            throw new ProviderException("TMDB request [{$path}] answered without a body.");
        }

        return $data;
    }

    private function request(): PendingRequest
    {
        return TmdbKey::on($this->httpClient(config()->string('tracker.tmdb.base_url')));
    }
}

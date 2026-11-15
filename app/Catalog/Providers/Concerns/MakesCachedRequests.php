<?php

namespace App\Catalog\Providers\Concerns;

use App\Catalog\ProviderCacheContext;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Response-cache plumbing shared by the outbound HTTP clients. One meaning of
 * "hit" for all of them: an entry is stored inside an envelope, so any answer
 * — an empty list, a 404 recorded as null — reads back as itself, and only a
 * missing envelope is a miss. A degraded ("empty") answer can be kept out of
 * the cache by a predicate, so a transient provider hiccup never freezes an
 * empty page for the whole TTL.
 *
 * The using client receives ProviderCacheContext through its constructor as
 * `$cacheContext`. A manual refresh skips the read here, and only here, so the
 * rest of the cache (rate limiters, locks, tokens) never notices a refresh.
 *
 * @property-read ProviderCacheContext $cacheContext
 */
trait MakesCachedRequests
{
    /** The envelope's one key; a cache value without it is not ours. */
    private const CACHED_ANSWER = 'cached_answer';

    /**
     * Read $key; on a miss run $fetch and store its answer — unless $isDegraded
     * reports it as degraded, in which case it is returned but not stored, so
     * the next request heals itself. During a manual refresh the first read
     * of a key is skipped and the fresh answer replaces the stored one.
     *
     * @param  Closure(): mixed  $fetch
     * @param  (Closure(mixed): bool)|null  $isDegraded
     */
    protected function cached(string $key, int $ttl, Closure $fetch, ?Closure $isDegraded = null): mixed
    {
        if (! $this->cacheContext->bypasses($key)) {
            $envelope = Cache::get($key);

            if (is_array($envelope) && array_key_exists(self::CACHED_ANSWER, $envelope)) {
                return $envelope[self::CACHED_ANSWER];
            }
        }

        $answer = $fetch();
        $this->cacheContext->recordFetched($key);

        if ($isDegraded === null || ! $isDegraded($answer)) {
            $this->storeCached($key, $answer, $ttl);
        }

        return $answer;
    }

    /**
     * Whatever is stored under $key, or $default on a miss — never a request.
     */
    protected function peekCached(string $key, mixed $default = null): mixed
    {
        $envelope = Cache::get($key);

        return is_array($envelope) && array_key_exists(self::CACHED_ANSWER, $envelope)
            ? $envelope[self::CACHED_ANSWER]
            : $default;
    }

    /**
     * Store an answer obtained some other way (a list that already carries
     * the details, a failure remembered for a short while).
     */
    protected function storeCached(string $key, mixed $answer, int $ttl): void
    {
        Cache::put($key, [self::CACHED_ANSWER => $answer], $ttl);
    }

    /**
     * `<namespace>:<md5>` of the request and its parameters. Parameters are
     * compared by content, so the same query asked with its keys in another
     * order is the same entry.
     *
     * @param  array<array-key, mixed>  $params
     */
    protected function cacheKey(string $namespace, string $request, array $params = []): string
    {
        return $namespace.':'.md5($request.'|'.json_encode(self::sortedKeys($params)));
    }

    /**
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     */
    private static function sortedKeys(array $params): array
    {
        if (! array_is_list($params)) {
            ksort($params);
        }

        return array_map(fn (mixed $value): mixed => is_array($value) ? self::sortedKeys($value) : $value, $params);
    }
}

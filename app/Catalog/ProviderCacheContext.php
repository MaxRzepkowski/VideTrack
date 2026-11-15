<?php

namespace App\Catalog;

/**
 * Request-scoped switch that makes the provider HTTP clients bypass their
 * response cache for the duration of a callback. Scheduled refreshes rely on
 * the cache TTL expiring between runs, but a manual "refresh this title" must
 * pull genuinely fresh data — the details cache lives for a day, so a new
 * episode or corrected field would not appear otherwise. The refresh action
 * wraps its provider calls in fresh(); the provider clients then skip their
 * cache read and re-cache what they fetch. Nothing else reads this flag, so
 * rate limiters, locks and tokens behave the same during a refresh.
 *
 * Each address is fetched once per scope: a second read of the same key
 * inside fresh() takes the answer fetched a moment ago, so building a tree
 * that asks for the show again under every part costs no second request.
 */
class ProviderCacheContext
{
    private bool $fresh = false;

    /** @var array<string, true> keys fetched since the outermost fresh() began */
    private array $fetched = [];

    /** Whether a read of $key skips the cache: inside fresh(), until the key is fetched. */
    public function bypasses(string $key): bool
    {
        return $this->fresh && ! isset($this->fetched[$key]);
    }

    /** Remembers that $key was just fetched; outside fresh() there is nothing to remember. */
    public function recordFetched(string $key): void
    {
        if ($this->fresh) {
            $this->fetched[$key] = true;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function fresh(callable $callback): mixed
    {
        $previous = $this->fresh;
        $this->fresh = true;

        try {
            return $callback();
        } finally {
            $this->fresh = $previous;

            if (! $previous) {
                $this->fetched = [];
            }
        }
    }
}

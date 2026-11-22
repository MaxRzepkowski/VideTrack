<?php

namespace App\Catalog\Wikidata;

use App\Catalog\ProviderCacheContext;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Catalog\Providers\Concerns\MakesCachedRequests;
use App\Catalog\Providers\Concerns\SendsHttpRequests;

/**
 * Thin, cached client for the public Wikidata SPARQL endpoint. Wikidata needs
 * no key but asks for a descriptive User-Agent; all reads go through the cache
 * so the same query within the TTL never hits the endpoint twice.
 */
class WikidataClient
{
    use MakesCachedRequests, SendsHttpRequests;

    // Fail fast: this runs on the deferred render path, so a slow endpoint
    // must free the worker quickly, and a retry would stack timeouts.
    private const TIMEOUT_SECONDS = 6;

    public function __construct(private readonly ProviderCacheContext $cacheContext) {}

    /** Whether the id names a Wikidata item, and so is safe to paste into a query. */
    public static function isItem(string $qid): bool
    {
        return preg_match('/^Q\d+$/', $qid) === 1;
    }

    /**
     * Run a SPARQL query and return its bindings. The default timeout is the
     * render path's; a background job that can afford to wait says how long.
     *
     * @return list<array<array-key, mixed>>
     */
    public function select(string $sparql, int $timeoutSeconds = self::TIMEOUT_SECONDS): array
    {
        $key = $this->cacheKey('wikidata', $sparql);

        try {
            /** @var list<array<array-key, mixed>> */
            return $this->cached($key, config()->integer('tracker.wikidata.cache.query'), fn (): array => $this->fetch($sparql, $timeoutSeconds));
        } catch (ProviderException|ProviderRejected $e) {
            // Remember the failure as "no answer" for a short while: otherwise
            // a slow or unreachable Wikidata re-runs the full timeout on every
            // render and, on the deferred path, exhausts the server's workers
            // under refresh-spam. This way it blocks at most once per window.
            $this->storeCached($key, [], config()->integer('tracker.wikidata.cache.failure'));

            throw $e;
        }
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function fetch(string $sparql, int $timeoutSeconds): array
    {
        $response = $this->guardConnection('Wikidata', fn () => $this->httpClient(
            config()->string('tracker.wikidata.base_url'),
            timeoutSeconds: $timeoutSeconds,
            retries: 0,
        )
            ->withHeaders(['User-Agent' => config()->string('tracker.wikidata.user_agent')])
            ->get('/sparql', ['query' => $sparql, 'format' => 'json']));

        if ($response->failed()) {
            throw $this->failure($response, 'Wikidata query');
        }

        $bindings = data_get($response->json(), 'results.bindings');

        return is_array($bindings) ? array_values(array_filter($bindings, 'is_array')) : [];
    }
}

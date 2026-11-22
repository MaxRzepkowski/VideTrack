<?php

namespace App\Catalog;

use App\Catalog\Contracts\SupportsBulkExternalIdLookup;
use App\Catalog\Contracts\SupportsExternalIdLookup;

/**
 * Translates a foreign catalogue's id (imdb, tvdb…) into the ref used by
 * the provider serving a media type. Sources that key their items by a
 * different catalogue than ours — a Trakt export, an incoming webhook —
 * would otherwise lose every entry whose bag of ids happens to miss ours.
 *
 * Answers are memoized for the life of the instance, misses included, so a
 * single import asks about a given id exactly once. Resolve one instance
 * per run (the container hands out a fresh one) and keep it around.
 */
final class ExternalRefLookup
{
    /** @var array<string, string|null> */
    private array $memo = [];

    public function __construct(
        private readonly MediaTypeRegistry $types,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * The sources the provider behind $type can translate from, best first.
     * Empty when the type is unknown here or its provider has no lookup.
     *
     * @return list<ExternalIdSource>
     */
    public function sourcesFor(string $type): array
    {
        $provider = $this->lookupFor($type);

        return $provider === null ? [] : $provider->externalIdSources();
    }

    /**
     * The provider's own ref for a foreign id, or null when it knows
     * nothing about it. A provider outage throws ProviderException and is
     * not memoized: "unknown" and "could not ask" are different answers,
     * and each caller decides what an outage costs it.
     */
    public function refFor(string $type, ExternalIdSource $source, string $id): ?string
    {
        $key = "{$type}:{$source->value}:{$id}";

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $provider = $this->lookupFor($type);

        if ($provider === null) {
            return $this->memo[$key] = null;
        }

        return $this->memo[$key] = $provider->refForExternalId($type, $source, $id);
    }

    /**
     * The provider's own refs for many foreign ids at once, where its
     * provider can be asked about them together: id => ref, or null for an
     * id it knows nothing about. Null instead when it can only be asked one
     * id at a time, which refFor() does.
     *
     * @param  list<string>  $ids
     * @return array<array-key, string|null>|null
     */
    public function refsFor(string $type, ExternalIdSource $source, array $ids): ?array
    {
        $provider = $this->lookupFor($type);

        if (! $provider instanceof SupportsBulkExternalIdLookup) {
            return null;
        }

        $refs = [];
        $asked = [];

        foreach ($ids as $id) {
            $key = "{$type}:{$source->value}:{$id}";

            if (array_key_exists($key, $this->memo)) {
                $refs[$id] = $this->memo[$key];
            } else {
                $asked[] = $id;
            }
        }

        $found = $asked === [] ? [] : $provider->refsForExternalIds($type, $source, $asked);

        foreach ($asked as $id) {
            $refs[$id] = $this->memo["{$type}:{$source->value}:{$id}"] = $found[$id] ?? null;
        }

        return $refs;
    }

    private function lookupFor(string $type): ?SupportsExternalIdLookup
    {
        if (! $this->types->has($type)) {
            return null;
        }

        $provider = $this->providers->forType($this->types->get($type));

        return $provider instanceof SupportsExternalIdLookup ? $provider : null;
    }
}

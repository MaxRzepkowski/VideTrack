<?php

namespace App\Catalog\Contracts;

use App\Catalog\ExternalIdSource;

/**
 * Capability: the provider can translate a foreign catalogue's id into its
 * own ref. Imports and incoming webhooks receive whatever id their source
 * happens to key items by (Trakt hands out imdb/tvdb next to tmdb), so an
 * entry the provider knows perfectly well can arrive under a name the
 * provider does not answer to.
 */
interface SupportsExternalIdLookup
{
    /**
     * Sources this provider can translate from, best first — callers walk
     * the list in order.
     *
     * @return list<ExternalIdSource>
     */
    public function externalIdSources(): array;

    /**
     * The provider's own ref for a foreign id, or null when it knows
     * nothing about it. Never throws for "not found" — only for an outage.
     */
    public function refForExternalId(string $type, ExternalIdSource $source, string $id): ?string;
}

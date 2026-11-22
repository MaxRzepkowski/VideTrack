<?php

namespace App\Catalog\Contracts;

use App\Catalog\ExternalIdSource;

/**
 * Capability: the provider translates many foreign ids in one request, where
 * asking about each alone would spend a request apiece of a tight limit (a
 * run naming hundreds of books at once).
 */
interface SupportsBulkExternalIdLookup extends SupportsExternalIdLookup
{
    /**
     * The provider's own ref for each foreign id it knows; an id it knows
     * nothing about is left out. Never throws for "not found", only for an outage.
     *
     * @param  list<string>  $ids
     * @return array<array-key, string> foreign id => ref (a numeric id keys as a number)
     */
    public function refsForExternalIds(string $type, ExternalIdSource $source, array $ids): array;
}

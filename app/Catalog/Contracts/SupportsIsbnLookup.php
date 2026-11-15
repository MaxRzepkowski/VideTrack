<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\MediaSummary;

/**
 * Capability: the provider can resolve a book from an ISBN. Lets external
 * bridges (e.g. Wikidata "based on") identify a title deterministically by
 * identifier instead of a fuzzy title search.
 */
interface SupportsIsbnLookup
{
    /**
     * The book carrying any of the given ISBN-13s (digits only), or null.
     * Editions of one work collapse onto a single book, so passing every
     * known ISBN of a work reliably lands on its book regardless of which
     * edition the catalogue happens to index.
     *
     * @param  list<string>  $isbns  ISBN-13s, digits only (no hyphens).
     */
    public function bookByIsbns(array $isbns, string $locale): ?MediaSummary;
}

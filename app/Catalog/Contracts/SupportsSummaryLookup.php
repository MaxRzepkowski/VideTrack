<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\MediaSummary;

/**
 * Capability: the provider gives the cards of many titles known by ref in one
 * request, without asking for each title's page. A card carries what a page's
 * summary does (its reach in `votes` included) and nothing of the page beyond.
 */
interface SupportsSummaryLookup
{
    /**
     * Each title's card; a title the provider does not have, or files as
     * adult material, is left out.
     *
     * @param  list<string>  $refs
     * @return array<array-key, MediaSummary> ref => card (a numeric ref keys as a number)
     */
    public function summaries(string $type, array $refs, string $locale): array;
}

<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\MediaSummary;

/**
 * Capability: free-text people search, as `person`-typed summaries (name as
 * title, profile as poster) — the search page's "People" scope.
 */
interface SupportsPeopleSearch
{
    /**
     * @return list<MediaSummary>
     */
    public function searchPeople(string $query, string $locale): array;
}

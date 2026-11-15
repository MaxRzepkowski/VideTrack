<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\MediaSummary;

/** Capability: free-text provider collection search. */
interface SupportsCollectionSearch
{
    /**
     * @return list<MediaSummary>
     */
    public function searchCollections(string $query, string $locale): array;
}

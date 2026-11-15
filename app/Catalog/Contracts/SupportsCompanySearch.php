<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\MediaSummary;

/** Capability: free-text studio and company search. */
interface SupportsCompanySearch
{
    /**
     * @return list<MediaSummary>
     */
    public function searchCompanies(string $query, string $locale): array;
}

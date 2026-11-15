<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\MediaSummary;

/**
 * Capability: the provider can list currently trending titles.
 */
interface SupportsTrending
{
    /**
     * @return list<MediaSummary>
     */
    public function trending(string $locale): array;
}

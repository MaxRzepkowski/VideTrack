<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;

/**
 * Capability: the provider can browse its people catalogue without a query.
 * Deliberately separate from SupportsPeopleSearch — browsing a catalogue and
 * looking someone up by name are different abilities and a provider may have
 * one without the other.
 */
interface SupportsPeopleBrowsing
{
    /**
     * @return Paginated<MediaSummary>
     */
    public function popularPeople(string $locale, int $page = 1): Paginated;
}

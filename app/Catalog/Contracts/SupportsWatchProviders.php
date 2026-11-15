<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\WatchAvailability;

/**
 * Capability: the provider can tell where a title is available to watch
 * (streaming / rent / buy) in a given region.
 */
interface SupportsWatchProviders
{
    /**
     * Offers for one region, or null when the title has none there (or the
     * type has no watch data at all).
     */
    public function watchProviders(string $type, string $ref, string $region): ?WatchAvailability;

    /**
     * Every region where the title has an offer of any kind, as ISO 3166-1
     * codes. Answers "is this watchable anywhere at all", which the release
     * dates alone can get wrong: those are volunteer-entered and lag the
     * licensing feeds behind this data, so a film can be buyable online while
     * carrying no digital release date.
     *
     * Deliberately dateless — the source knows where a title is, never since
     * when.
     *
     * @return list<string>
     */
    public function watchProviderRegions(string $type, string $ref): array;

    /**
     * Regions the provider has watch data for, code => display name.
     *
     * @return array<string, string>
     */
    public function watchRegions(string $locale): array;
}

<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\DiscoverFilter;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;

/**
 * Capability: the provider can narrow its catalogue by a set of filters it
 * describes itself. Deliberately independent of SupportsDiscovery —
 * a provider may implement either, both or neither, and one implementing only
 * this one is a first-class citizen of the discover page: it appears in the
 * type select and in the header's mega-menu, and it serves the by-name `genre`
 * parameter through its own genres filter.
 *
 * The contract promises NUMBERED PAGES OVER THE WHOLE FILTERED SET. A provider
 * whose API cannot be queried by filters must not implement it by post-filtering
 * one page of a ranking — that yields a listing whose pagination lies. Filtering
 * a complete, finite set locally is fine.
 */
interface SupportsFilteredDiscovery
{
    /**
     * Media type codes this provider can filter.
     *
     * @return list<string>
     */
    public function filterableTypes(): array;

    /**
     * The filters available for a type. `$region` is the user's streaming
     * region, resolved by the caller (the core stays media-agnostic and the
     * provider never sees a user); providers with no region-dependent filter
     * ignore it.
     *
     * A dictionary that fails to load degrades to its own filter marked
     * `unavailable` — never to an exception that takes the whole page down.
     *
     * `$requested` contains the untrusted raw `f` query solely so an open
     * lookup filter can name the refs already present in the URL. Providers
     * must validate and cap it before making dictionary requests.
     *
     * @param  array<string, mixed>  $requested
     * @return list<DiscoverFilter>
     */
    public function discoverFilters(string $type, string $locale, string $region, array $requested = []): array;

    /**
     * Results for an already NORMALIZED filter map (see
     * NormalizeDiscoverFilters): keys are filter keys, values are a string for
     * a select, a list of strings for a multi-select, and a `{from, to}` pair
     * of nullable bounds for a range. The provider can trust the shape but
     * still drops values outside its own whitelist.
     *
     * @param  array<string, string|list<string>|array{from: int|null, to: int|null}>  $filters
     * @return Paginated<MediaSummary>
     */
    public function discoverFiltered(
        string $type,
        array $filters,
        string $locale,
        string $region,
        int $page = 1,
    ): Paginated;
}

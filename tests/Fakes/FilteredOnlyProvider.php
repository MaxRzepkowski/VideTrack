<?php

namespace Tests\Fakes;

use App\Catalog\Contracts\MetadataProvider;
use App\Catalog\Contracts\SupportsFilteredDiscovery;
use App\Catalog\Data\DiscoverFilter;
use App\Catalog\Data\DiscoverFilterOption;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;
use App\Catalog\DiscoverFilterKind;
use App\Catalog\ItemNotFoundException;

/**
 * A provider that can filter but cannot browse: no SupportsTrending, no
 * SupportsDiscovery. It exists to prove the filtered contract stands on its
 * own — such a provider must still reach the type select, the mega-menu, the
 * filtered grid and the by-name `genre` redirect.
 */
class FilteredOnlyProvider implements MetadataProvider, SupportsFilteredDiscovery
{
    public function code(): string
    {
        return 'tmdb';
    }

    public function search(string $query, string $locale): array
    {
        return [];
    }

    public function details(string $type, string $ref, string $locale, ?string $region = null): MediaDetails
    {
        throw new ItemNotFoundException('The fake provider has no details.');
    }

    public function filterableTypes(): array
    {
        return ['movie', 'show'];
    }

    public function discoverFilters(string $type, string $locale, string $region, array $requested = []): array
    {
        return [
            new DiscoverFilter('genres', 'Genres', DiscoverFilterKind::MultiSelect, [
                new DiscoverFilterOption('18', $locale === 'pl' ? 'Dramat' : 'Drama'),
            ]),
        ];
    }

    public function discoverFiltered(
        string $type,
        array $filters,
        string $locale,
        string $region,
        int $page = 1,
    ): Paginated {
        return new Paginated(
            items: [new MediaSummary(type: $type, ref: '300', provider: $this->code(), title: 'Filtered Only Title', year: 2019, posterPath: '/f.jpg')],
            page: $page,
            totalPages: 1,
            total: 1,
        );
    }
}

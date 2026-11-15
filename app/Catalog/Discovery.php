<?php

namespace App\Catalog;

use App\Catalog\Contracts\SupportsDiscovery;
use App\Catalog\Contracts\SupportsFilteredDiscovery;
use App\Catalog\Contracts\SupportsPeopleBrowsing;
use App\Catalog\Contracts\SupportsTrending;
use App\Catalog\Data\DiscoverQuery;

/**
 * What the discover page can browse: the "can this provider build discover
 * listings for this type?" check — the `instanceof SupportsDiscovery`
 * capability guard plus the per-type `discoverableTypes()` membership — and
 * the types the page offers and answers.
 */
final readonly class Discovery
{
    public function __construct(
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
    ) {}

    /**
     * @phpstan-assert-if-true SupportsDiscovery $provider
     */
    public static function supports(object $provider, string $type): bool
    {
        return $provider instanceof SupportsDiscovery
            && in_array($type, $provider->discoverableTypes(), true);
    }

    /**
     * Everything the discover page can offer at all: searchable types whose
     * provider fills at least one surface — a browse section or a filtered
     * listing. ComicVine serves neither, so comics never reach the select.
     *
     * The membership is checked against the capability interfaces only, not
     * against the per-type lists: this runs for every type on every request
     * (the header's mega-menu shares it), and `filterableTypes()` may cost a
     * provider call. The per-type narrowing happens where the listing is
     * actually built.
     *
     * Note the list is NOT only media type codes: `person` is appended when a
     * provider can browse people. Discover browses one entity at a
     * time and the select gates on this one list, so an entity that is not a
     * media type belongs here rather than in a second, parallel gate.
     *
     * @return list<string>
     */
    public function browsableCodes(): array
    {
        $codes = array_values(array_filter(
            $this->types->searchableCodes(),
            function (string $code): bool {
                $provider = $this->providers->forType($this->types->get($code));

                return $provider instanceof SupportsTrending
                    || $provider instanceof SupportsDiscovery
                    || $provider instanceof SupportsFilteredDiscovery;
            },
        ));

        return [...$codes, ...$this->people()];
    }

    /** Whether a title's genres can link to the discover page's genre listing of its type. */
    public function browsesGenresOf(string $type): bool
    {
        return $this->types->has($type) && self::supports($this->providers->forType($this->types->get($type)), $type);
    }

    /** What the page opens on when no type is asked for: the first it can browse, in the registry's order. */
    public function defaultType(): ?string
    {
        return $this->browsableCodes()[0] ?? $this->pageTypes()[0] ?? null;
    }

    /**
     * Every `type` the discover page answers: each searchable type (one whose
     * provider fills no surface shows an empty page) and `person` when a
     * provider can browse people. Anything else is a 404.
     *
     * @return list<string>
     */
    public function pageTypes(): array
    {
        return [...$this->types->searchableCodes(), ...$this->people()];
    }

    /** @return list<string> the people grid, when a provider can browse people */
    private function people(): array
    {
        return $this->providers->firstSupporting(SupportsPeopleBrowsing::class) === null ? [] : [DiscoverQuery::PEOPLE];
    }
}

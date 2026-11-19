<?php

namespace App\Actions\Media;

use App\Catalog\Contracts\SupportsFilteredDiscovery;
use App\Catalog\Contracts\SupportsPeopleBrowsing;
use App\Catalog\Data\DiscoverFilter;
use App\Catalog\Data\DiscoverFilterOption;
use App\Catalog\Data\DiscoverQuery;
use App\Catalog\Data\GenreOption;
use App\Catalog\Data\Paginated;
use App\Catalog\DiscoverSections;
use App\Catalog\Discovery;
use App\Catalog\FilteredDiscovery;
use App\Catalog\GenreResolver;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderCalls;
use App\Catalog\ProviderRegistry;
use App\Catalog\ViewerRegion;
use App\Http\Data\DiscoverPage;
use App\Library\CreatorStateQuery;
use App\Library\LibraryStates;
use App\Models\User;
use App\Support\PageInfo;
use App\Support\Payload;

/**
 * The discover page for ONE type, in one of three modes:
 *
 *  - browse: trending, popular and upcoming sections, plus a paginated
 *    by-genre listing for a provider without filtered discovery;
 *  - filtered: a provider with SupportsFilteredDiscovery gets `f[...]`
 *    parameters describing a paginated grid that replaces the sections; the
 *    by-name `genre` parameter is redirected into it, so such links land on
 *    the canonical address;
 *  - people: `type=person` is a paginated grid of the people-capable
 *    provider's popular catalogue, with no sections, genres or filters.
 *
 * Each type is served by exactly one provider, so a page only ever calls
 * that provider — a rate-limited module is never touched while browsing
 * another type. A provider outage degrades the section it fed and the page
 * says so (`failed`).
 */
final readonly class BuildDiscoverPage
{
    public function __construct(
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
        private LibraryStates $states,
        private NormalizeDiscoverFilters $normalize,
        private GenreResolver $genreResolver,
        private CreatorStateQuery $creatorStates,
        private Discovery $discovery,
        private DiscoverSections $sections,
    ) {}

    /**
     * `$query->type` is one of Discovery::pageTypes().
     */
    public function execute(User $viewer, DiscoverQuery $query, string $locale): DiscoverPage
    {
        $calls = new ProviderCalls;

        if ($query->isPeople()) {
            return $this->people($viewer, $query, $locale, $calls);
        }

        $type = $query->type;
        $provider = $this->providers->forType($this->types->get($type));
        // The core resolves the region and hands it over, so the provider
        // never has to know there is a user.
        $region = ViewerRegion::resolve($viewer, $locale);
        $props = [];
        // Every card the page shows, whichever mode filled it, the filtered
        // grid too: without them the library states hold nothing for those
        // cards and each claims "unwatched, unrated, not on any list".
        $cards = [];

        if (FilteredDiscovery::supports($provider, $type)) {
            // A filter is named; an unnamed entry of the raw parameter tells the
            // provider nothing.
            $filters = $calls->attempt(fn (): array => $provider->discoverFilters($type, $locale, $region, Payload::named($query->filters)), []);

            $detour = $this->genreByNameDetour($provider, $query, $filters, $locale, $region, $calls);

            if ($detour !== null) {
                return $detour;
            }

            $active = $this->normalize->execute($filters, $query->filters);
            $props = ['filters' => $filters, 'activeFilters' => (object) $active, 'filterCount' => count($active)];

            if ($active !== []) {
                // A URL naming a service whose dictionary is down must not
                // quietly answer with the wider catalogue — say it failed.
                if ($this->dictionaryMissing($filters, $active)) {
                    $calls->markFailed();
                } else {
                    $listing = $calls->attempt(fn (): Paginated => $provider->discoverFiltered($type, $active, $locale, $region, $query->page), null);

                    // Past the last page the URL is lying; rewrite it rather
                    // than show an empty grid. A degraded call has no page
                    // count at all, so it never triggers this.
                    if ($listing !== null && $query->page > $listing->totalPages) {
                        return DiscoverPage::redirect($query, page: $listing->totalPages);
                    }

                    $props['filteredItems'] = $listing->items ?? [];
                    $cards = [...$cards, ...$listing->items ?? []];
                    $props['filteredPagination'] = $listing?->pageInfo() ?? PageInfo::empty();
                }
            }
        }

        if (Discovery::supports($provider, $type)) {
            $genres = $calls->attempt(fn (): array => $provider->genres($type, $locale), []);
            $props['genres'] = $genres;

            if ($query->genre !== null && $genres !== []) {
                $genre = $this->genreResolver->resolve(
                    $query->genre,
                    $genres,
                    $locale,
                    fn (string $other): array => $calls->attempt(fn (): array => $provider->genres($type, $other), []),
                );

                if ($genre === null) {
                    return DiscoverPage::notFound();
                }

                $listing = $calls->attempt(fn (): Paginated => $provider->byGenre($type, $genre->ref, $locale, $query->page), null);

                $props['genre'] = $genre->ref;
                $props['genreName'] = $genre->name;
                $props['genreItems'] = $listing->items ?? [];
                $cards = [...$cards, ...$listing->items ?? []];
                $props['genrePagination'] = $listing?->pageInfo() ?? PageInfo::empty();
            }
        }

        // A dedicated genre page and a filtered grid both replace the browse
        // sections rather than sitting under them — there is no mixed mode.
        if (! isset($props['genre']) && ($props['filterCount'] ?? 0) === 0) {
            $sections = $this->sections->of($type, $locale, $calls);
            $props = [...$props, 'trending' => $sections->trending, 'popular' => $sections->popular, 'upcoming' => $sections->upcoming];
            $cards = [...$cards, ...$sections->cards()];
        }

        $props = $this->props($type, $calls, $props);

        $props['userStates'] = fn (): array => $this->states->resolve($viewer, $cards);

        return DiscoverPage::render($props);
    }

    /**
     * The people grid. People carry no watched or rating state; a person card
     * knows only list membership and the bell.
     */
    private function people(User $viewer, DiscoverQuery $query, string $locale, ProviderCalls $calls): DiscoverPage
    {
        $provider = $this->providers->firstSupporting(SupportsPeopleBrowsing::class);

        $listing = $provider instanceof SupportsPeopleBrowsing
            ? $calls->attempt(fn (): Paginated => $provider->popularPeople($locale, $query->page), null)
            : null;

        // Past the last page the URL is lying; rewrite it rather than show an
        // empty grid. A degraded call has no page count, so it never fires.
        if ($listing !== null && $query->page > $listing->totalPages) {
            return DiscoverPage::redirect($query, page: $listing->totalPages);
        }

        $people = $listing->items ?? [];

        return DiscoverPage::render([
            ...$this->props(DiscoverQuery::PEOPLE, $calls, [
                'people' => $people,
                'peoplePagination' => $listing?->pageInfo() ?? PageInfo::empty(),
            ]),
            'userStates' => [],
            'creatorStates' => fn (): array => $this->creatorStates->ofSummaries($viewer, $people),
        ]);
    }

    /**
     * Every prop the page takes, whichever mode filled which of them.
     *
     * @param  array<string, mixed>  $filled
     * @return array<string, mixed>
     */
    private function props(string $type, ProviderCalls $calls, array $filled): array
    {
        return [
            'type' => $type,
            // Only types whose provider can fill at least one surface; shared
            // with the header's mega-menu, so it lives in one place.
            'availableTypes' => $this->discovery->browsableCodes(),
            'trending' => [],
            'popular' => [],
            'upcoming' => [],
            'genres' => [],
            'genre' => null,
            'genreName' => null,
            'genreItems' => [],
            'genrePagination' => null,
            // null = this provider has no filtered discovery, so the page
            // stays the plain browse page.
            'filters' => null,
            'activeFilters' => (object) [],
            'filterCount' => null,
            'filteredItems' => [],
            'filteredPagination' => null,
            'people' => [],
            'peoplePagination' => null,
            ...$filled,
            'failed' => $calls->failed(),
        ];
    }

    /**
     * The by-name `?genre=` parameter as the filtered grid's genre filter —
     * genre chips all over the app link by name, and those links must keep
     * working once a provider gains the filtered contract: the redirect to the
     * canonical address, or no page for a name no genre has. Null when there is
     * nothing to translate (the page is already canonical).
     *
     * @param  list<DiscoverFilter>  $filters
     */
    private function genreByNameDetour(
        SupportsFilteredDiscovery $provider,
        DiscoverQuery $query,
        array $filters,
        string $locale,
        string $region,
        ProviderCalls $calls,
    ): ?DiscoverPage {
        $requested = $query->genre;
        $genres = $this->genreFilter($filters);

        // No genres filter (or its dictionary is down) — nothing to translate
        // the name against, so the parameter is simply ignored.
        if ($requested === null || $genres === []) {
            return null;
        }

        $raw = $query->filters ?? [];

        // Both spellings at once: `f` wins and `genre` is cut, so the
        // page never carries two competing genre selections.
        if (isset($raw['genres'])) {
            return DiscoverPage::redirect($query, filters: $raw);
        }

        $genre = $this->genreResolver->resolve(
            $requested,
            $genres,
            $locale,
            fn (string $other): array => $this->genreFilter(
                $calls->attempt(fn (): array => $provider->discoverFilters($query->type, $other, $region), []),
            ),
        );

        // A typo in the one parameter the whole app links by should be loud —
        // unlike an aged `f[...]` value, which is dropped in silence.
        if ($genre === null) {
            return DiscoverPage::notFound();
        }

        return DiscoverPage::redirect($query, filters: [...$raw, 'genres' => [$genre->ref]]);
    }

    /**
     * The options of the `genres` filter, as genres.
     *
     * @param  list<DiscoverFilter>  $filters
     * @return list<GenreOption>
     */
    private function genreFilter(array $filters): array
    {
        foreach ($filters as $filter) {
            if ($filter->key === 'genres') {
                return array_map(
                    fn (DiscoverFilterOption $option): GenreOption => new GenreOption($option->value, $option->label),
                    $filter->options,
                );
            }
        }

        return [];
    }

    /**
     * Is any active filter one whose dictionary failed to load? Such a value
     * cannot be honoured, and honouring the rest would answer a request for
     * "on Netflix" with the whole catalogue.
     *
     * @param  list<DiscoverFilter>  $filters
     * @param  array<string, mixed>  $active
     */
    private function dictionaryMissing(array $filters, array $active): bool
    {
        foreach ($filters as $filter) {
            if ($filter->unavailable && isset($active[$filter->key])) {
                return true;
            }
        }

        return false;
    }
}

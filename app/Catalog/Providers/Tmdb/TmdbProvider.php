<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\ChartOrder;
use App\Catalog\Contracts\MetadataProvider;
use App\Catalog\Contracts\SupportsChangeFeed;
use App\Catalog\Contracts\SupportsCharts;
use App\Catalog\Contracts\SupportsCollections;
use App\Catalog\Contracts\SupportsCollectionSearch;
use App\Catalog\Contracts\SupportsCompanyPage;
use App\Catalog\Contracts\SupportsCompanySearch;
use App\Catalog\Contracts\SupportsCrewRoles;
use App\Catalog\Contracts\SupportsDiscovery;
use App\Catalog\Contracts\SupportsEpisodeRatings;
use App\Catalog\Contracts\SupportsExternalIdLookup;
use App\Catalog\Contracts\SupportsFilteredDiscovery;
use App\Catalog\Contracts\SupportsHierarchy;
use App\Catalog\Contracts\SupportsNetworkPage;
use App\Catalog\Contracts\SupportsPeopleBrowsing;
use App\Catalog\Contracts\SupportsPeopleSearch;
use App\Catalog\Contracts\SupportsPersonPage;
use App\Catalog\Contracts\SupportsTrending;
use App\Catalog\Contracts\SupportsWatchProviders;
use App\Catalog\Data\ChangeReport;
use App\Catalog\Data\CollectionDetails;
use App\Catalog\Data\CompanyDetails;
use App\Catalog\Data\CreditPage;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;
use App\Catalog\Data\PersonDetails;
use App\Catalog\Data\WatchAvailability;
use App\Catalog\Department;
use App\Catalog\ExternalIdSource;
use App\Catalog\ItemNotFoundException;
use App\Support\Locales;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * TMDB implementation of the metadata contracts: the face the registry knows,
 * handing each capability to the class that serves it (titles, seasons and
 * episodes, discovery and its filters, creators, where to watch).
 *
 * Opaque refs: movie "603", show "1399", season "1399:2", episode "1399:2:9"
 * (TMDB has no direct fetch-by-id for seasons/episodes, so hierarchical refs
 * carry the path). Only the TMDB module knows that encoding.
 *
 * Each method is described where it is implemented, not here.
 */
class TmdbProvider implements MetadataProvider, SupportsChangeFeed, SupportsCharts, SupportsCollections, SupportsCollectionSearch, SupportsCompanyPage, SupportsCompanySearch, SupportsCrewRoles, SupportsDiscovery, SupportsEpisodeRatings, SupportsExternalIdLookup, SupportsFilteredDiscovery, SupportsHierarchy, SupportsNetworkPage, SupportsPeopleBrowsing, SupportsPeopleSearch, SupportsPersonPage, SupportsTrending, SupportsWatchProviders
{
    public function __construct(
        private readonly TmdbClient $client,
        private readonly TmdbPayload $payload,
        private readonly TmdbTitles $titles,
        private readonly TmdbEpisodes $episodes,
        private readonly TmdbEpisodeLists $episodeLists,
        private readonly TmdbDiscoverFilters $filters,
        private readonly TmdbDiscovery $discovery,
        private readonly TmdbCreators $creators,
        private readonly TmdbCreatorSearch $creatorSearch,
        private readonly TmdbWatchProviders $watch,
        private readonly TmdbChanges $changes,
    ) {}

    public function code(): string
    {
        return $this->payload->code();
    }

    public function search(string $query, string $locale): array
    {
        $data = $this->client->get('search/multi', [
            'query' => $query,
            'language' => $this->payload->language($locale),
            'include_adult' => false,
        ], $this->payload->ttl('search'), 'results');

        $results = [];

        foreach (Payload::rows($data['results'] ?? null) as $result) {
            $results[] = match ($result['media_type'] ?? null) {
                'movie' => $this->payload->movieSummary($result),
                'tv' => $this->payload->showSummary($result),
                default => null,
            };
        }

        return array_values(array_filter($results));
    }

    public function details(string $type, string $ref, string $locale, ?string $region = null): MediaDetails
    {
        $region ??= Locales::region($locale);

        return match ($type) {
            'movie' => $this->titles->movieDetails($ref, $locale, $region),
            'show' => $this->titles->showDetails($ref, $locale, $region),
            'season' => $this->episodes->seasonDetails($ref, $locale, $region),
            'episode' => $this->episodes->episodeDetails($ref, $locale, $region),
            // A type routed here that TMDB does not serve is a bug, not a missing title.
            default => throw new LogicException("TMDB does not serve details for type [{$type}]."),
        };
    }

    public function parentRef(string $type, string $ref): ?string
    {
        $cut = strrpos($ref, ':');

        return $cut === false ? null : substr($ref, 0, $cut);
    }

    /** TMDB nests by number, colon-separated: show → "show:season:episode". */
    public function childRef(string $type, string $ref, int $number): ?string
    {
        return match ($type) {
            'show', 'season' => "{$ref}:{$number}",
            default => null,
        };
    }

    public function externalIdSources(): array
    {
        return [ExternalIdSource::Imdb, ExternalIdSource::Tvdb];
    }

    /**
     * `find/` is TMDB's index of the other catalogues' ids. It splits hits
     * by kind, so the type picks the bucket to read — one id never lands in
     * two of them. An id TMDB does not carry answers 200 with every bucket
     * empty; only a real outage raises, so the caller can treat null as
     * "unknown here" without a second meaning.
     */
    public function refForExternalId(string $type, ExternalIdSource $source, string $id): ?string
    {
        $bucket = match ($type) {
            'movie' => 'movie_results',
            'show' => 'tv_results',
            'episode' => 'tv_episode_results',
            default => null,
        };

        $externalSource = match ($source) {
            ExternalIdSource::Imdb => 'imdb_id',
            ExternalIdSource::Tvdb => 'tvdb_id',
            default => null,
        };

        if ($bucket === null || $externalSource === null || $id === '') {
            return null;
        }

        try {
            $data = $this->client->get('find/'.rawurlencode($id), [
                'external_source' => $externalSource,
            ], $this->payload->ttl('details'));
        } catch (ItemNotFoundException) {
            return null;
        }

        $results = is_array($data[$bucket] ?? null) ? $data[$bucket] : [];
        $first = is_array($results[0] ?? null) ? $results[0] : [];

        // An episode is not named by a number of its own here: the hit
        // carries the show it belongs to and where in it it sits, which is
        // exactly the three parts of our ref.
        if ($type === 'episode') {
            return $this->episodeRef($first);
        }

        $ref = $first['id'] ?? null;

        if (is_int($ref)) {
            return (string) $ref;
        }

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    /**
     * @param  array<array-key, mixed>  $result  one entry of `tv_episode_results`
     */
    private function episodeRef(array $result): ?string
    {
        $show = $result['show_id'] ?? null;
        $season = $result['season_number'] ?? null;
        $number = $result['episode_number'] ?? null;

        if (! is_int($show) || ! is_int($season) || ! is_int($number)) {
            return null;
        }

        return "{$show}:{$season}:{$number}";
    }

    public function trending(string $locale): array
    {
        return $this->discovery->trending($locale);
    }

    public function leadJobs(Department $department): array
    {
        return TmdbCredits::leadJobs($department);
    }

    public function chartedTypes(): array
    {
        return $this->discovery->filterableTypes();
    }

    public function chart(string $type, ChartOrder $order, ?int $fromYear, ?int $toYear, string $locale, int $page = 1): Paginated
    {
        return $this->discovery->chart($type, $order, $fromYear, $toYear, $locale, $page);
    }

    public function discoverableTypes(): array
    {
        return $this->discovery->discoverableTypes();
    }

    public function popular(string $type, string $locale): array
    {
        return $this->discovery->popular($type, $locale);
    }

    public function upcoming(string $type, string $locale): array
    {
        return $this->discovery->upcoming($type, $locale);
    }

    public function genres(string $type, string $locale): array
    {
        return $this->filters->genres($type, $locale);
    }

    public function byGenre(string $type, string $genreRef, string $locale, int $page = 1): Paginated
    {
        return $this->discovery->byGenre($type, $genreRef, $locale, $page);
    }

    public function filterableTypes(): array
    {
        return $this->discovery->filterableTypes();
    }

    public function discoverFilters(string $type, string $locale, string $region, array $requested = []): array
    {
        return $this->filters->discoverFilters($type, $locale, $region, $requested);
    }

    public function discoverFiltered(
        string $type,
        array $filters,
        string $locale,
        string $region,
        int $page = 1,
    ): Paginated {
        return $this->discovery->discoverFiltered($type, $filters, $locale, $region, $page);
    }

    public function person(string $ref, string $locale): PersonDetails
    {
        return $this->creators->person($ref, $locale);
    }

    public function network(string $ref, string $locale): CompanyDetails
    {
        return $this->creators->network($ref, $locale);
    }

    public function networkCredits(string $ref, string $locale, int $page): CreditPage
    {
        return $this->creators->networkCredits($ref, $locale, $page);
    }

    public function company(string $ref, string $locale): CompanyDetails
    {
        return $this->creators->company($ref, $locale);
    }

    public function companyCredits(string $ref, string $locale, int $page): CreditPage
    {
        return $this->creators->companyCredits($ref, $locale, $page);
    }

    public function searchPeople(string $query, string $locale): array
    {
        return $this->creatorSearch->searchPeople($query, $locale);
    }

    public function popularPeople(string $locale, int $page = 1): Paginated
    {
        return $this->creators->popularPeople($locale, $page);
    }

    public function searchCollections(string $query, string $locale): array
    {
        return $this->creatorSearch->searchCollections($query, $locale);
    }

    public function searchCompanies(string $query, string $locale): array
    {
        return $this->creatorSearch->searchCompanies($query, $locale);
    }

    public function episodeRatings(string $ref, string $locale): array
    {
        return $this->episodeLists->episodeRatings($ref, $locale);
    }

    /**
     * Every episode of a show in the same batched requests the ratings chart
     * uses — one round-trip per 20 seasons, not one per season.
     *
     * @return list<MediaSummary>
     */
    public function leaves(string $type, string $ref, string $locale): array
    {
        return $this->episodeLists->leaves($type, $ref, $locale);
    }

    public function collection(string $ref, string $locale): CollectionDetails
    {
        return $this->titles->collection($ref, $locale);
    }

    public function watchProviders(string $type, string $ref, string $region): ?WatchAvailability
    {
        return $this->watch->watchProviders($type, $ref, $region);
    }

    public function watchProviderRegions(string $type, string $ref): array
    {
        return $this->watch->watchProviderRegions($type, $ref);
    }

    public function watchRegions(string $locale): array
    {
        return $this->watch->watchRegions($locale);
    }

    /** Films and shows both have a list of changes. */
    public function changeFeedTypes(): array
    {
        return ['movie', 'show'];
    }

    /**
     * A film has no parts, so its list names every change. A show's names
     * only some of its seasons' and episodes' changes.
     */
    public function completeChangeFeedTypes(): array
    {
        return ['movie'];
    }

    public function changedAmong(string $type, array $refs, CarbonImmutable $since): ChangeReport
    {
        return $this->changes->changedAmong($type, $refs, $since);
    }
}

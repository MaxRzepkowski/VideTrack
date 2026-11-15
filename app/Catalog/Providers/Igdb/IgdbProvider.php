<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Contracts\MetadataProvider;
use App\Catalog\Contracts\SupportsCollections;
use App\Catalog\Contracts\SupportsCompanyPage;
use App\Catalog\Contracts\SupportsDiscovery;
use App\Catalog\Contracts\SupportsEvents;
use App\Catalog\Contracts\SupportsExternalIdLookup;
use App\Catalog\Contracts\SupportsFilteredDiscovery;
use App\Catalog\Contracts\SupportsFranchises;
use App\Catalog\Contracts\SupportsPlatforms;
use App\Catalog\Contracts\SupportsTrending;
use App\Catalog\Data\CollectionDetails;
use App\Catalog\Data\CompanyDetails;
use App\Catalog\Data\CreditPage;
use App\Catalog\Data\EventData;
use App\Catalog\Data\FranchiseDetails;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;
use App\Catalog\Data\PlatformDetails;
use App\Catalog\ExternalIdSource;
use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * IGDB implementation of the metadata contracts — the `game` module: the face
 * the registry knows, handing a game's page, a company's page and discovery to
 * the classes that serve them.
 * Games are flat (no hierarchy); refs are IGDB numeric
 * ids. IGDB serves English metadata only, so the locale merely picks the
 * age-rating organization. Image paths are absolute CDN URLs (IGDB sizes
 * are baked into the URL, unlike TMDB's size-prefix scheme).
 *
 * Each method is described where it is implemented, not here.
 */
class IgdbProvider implements MetadataProvider, SupportsCollections, SupportsCompanyPage, SupportsDiscovery, SupportsEvents, SupportsExternalIdLookup, SupportsFilteredDiscovery, SupportsFranchises, SupportsPlatforms, SupportsTrending
{
    /**
     * Steam in /external_game_sources. The per-row `category` enum that named
     * it (steam = 1) is deprecated in favour of this reference (api-docs.igdb.com,
     * External Game, read 2026-09-27); Yamtrack queries the same value.
     */
    private const STEAM_SOURCE = 1;

    /**
     * IGDB orders a search by how close the name is, nothing else: "cyberpunk"
     * finds a dozen unknown "Cyberpunk …" before Cyberpunk 2077. A wider pool
     * is asked for and the games people know are lifted to the top of it.
     */
    public const SEARCH_POOL = 50;

    private const SEARCH_RESULTS = 20;

    public function __construct(
        private readonly IgdbClient $client,
        private readonly IgdbPayload $payload,
        private readonly IgdbGames $games,
        private readonly IgdbSeries $series,
        private readonly IgdbFranchises $franchises,
        private readonly IgdbPlatforms $platforms,
        private readonly IgdbEvents $eventList,
        private readonly IgdbCompanies $companies,
        private readonly IgdbDiscovery $discovery,
    ) {}

    public function code(): string
    {
        return $this->payload->code();
    }

    public function externalIdSources(): array
    {
        return [ExternalIdSource::Steam];
    }

    /**
     * A Steam app id, as IGDB files it under external_games. Only games;
     * one request per id, which the resolver then keeps for good.
     */
    public function refForExternalId(string $type, ExternalIdSource $source, string $id): ?string
    {
        if ($type !== 'game' || $source !== ExternalIdSource::Steam || ! ctype_digit($id)) {
            return null;
        }

        $rows = $this->client->query(
            'external_games',
            sprintf('fields game; where uid = "%s" & external_game_source = %d; limit 1;', $id, self::STEAM_SOURCE),
            $this->payload->ttl('details'),
        );

        $game = $rows[0]['game'] ?? null;

        return is_int($game) ? (string) $game : null;
    }

    public function search(string $query, string $locale): array
    {
        // version_parent filters out re-releases of the same game (they
        // duplicate the parent under the same name).
        $data = $this->client->query(
            'games',
            'search "'.$this->payload->escape($query).'"; '
            .'fields '.IgdbPayload::SUMMARY_FIELDS.', total_rating_count, '.IgdbPayload::ALTERNATIVE_NAME_FIELDS.'; '
            .'where cover != null & version_parent = null & '.GameType::clause(GameType::searchable()).'; limit '.self::SEARCH_POOL.';',
            $this->payload->ttl('search'),
            requireResults: true,
        );

        $games = Payload::rows($data);
        // Known by how many rated it or wait for it; a stable sort keeps IGDB's
        // name order among equals, so the unknown keep theirs below.
        $known = fn (array $game): int => (Payload::int($game, 'total_rating_count') ?? 0) + (Payload::int($game, 'hypes') ?? 0);
        usort($games, fn (array $a, array $b): int => $known($b) <=> $known($a));

        return array_map(fn (array $game): MediaSummary => $this->payload->summary($game), array_slice($games, 0, self::SEARCH_RESULTS));
    }

    public function details(string $type, string $ref, string $locale, ?string $region = null): MediaDetails
    {
        return $this->games->details($type, $ref, $locale, $region);
    }

    public function collection(string $ref, string $locale): CollectionDetails
    {
        return $this->series->collection($ref);
    }

    public function franchise(string $ref, string $locale): FranchiseDetails
    {
        return $this->franchises->franchise($ref);
    }

    /** @return list<EventData> */
    public function events(CarbonImmutable $since): array
    {
        return $this->eventList->since($since);
    }

    public function event(string $ref): EventData
    {
        return $this->eventList->event($ref);
    }

    /** @return list<EventData> */
    public function eventsShowing(string $ref): array
    {
        return $this->eventList->showing($ref);
    }

    public function platform(string $ref, string $locale): PlatformDetails
    {
        return $this->platforms->platform($ref);
    }

    public function company(string $ref, string $locale): CompanyDetails
    {
        return $this->companies->company($ref, $locale);
    }

    public function companyCredits(string $ref, string $locale, int $page): CreditPage
    {
        return $this->companies->companyCredits($ref, $locale, $page);
    }

    public function trending(string $locale): array
    {
        return $this->discovery->trending($locale);
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
        return $this->discovery->genres($type, $locale);
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
        return $this->discovery->discoverFilters($type, $locale, $region, $requested);
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
}

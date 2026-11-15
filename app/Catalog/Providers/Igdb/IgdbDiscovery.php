<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Data\DiscoverFilter;
use App\Catalog\Data\DiscoverFilterOption;
use App\Catalog\Data\GenreOption;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;
use App\Catalog\DiscoverFilterKind;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Support\Payload;

/**
 * Discover for games: trending and popular by IGDB's popularity primitives,
 * upcoming releases, genres, and the filtered grid with what it offers.
 */
final readonly class IgdbDiscovery
{
    /** The resting sort order of the filtered grid, kept out of the URL. */
    private const DEFAULT_SORT = 'total_rating_count desc';

    /** Sorts by score, meaningless without a floor on how many rated. */
    private const RATED_SORTS = ['total_rating_count desc', 'total_rating desc'];

    private const MIN_RATINGS = 5;

    /** Most followed ahead of release: only what is still to come, a date or not. */
    private const ANTICIPATED_SORT = 'hypes desc';

    /** IGDB popularity_primitives types: 1 = IGDB visits, 3 = playing. */
    private const POPULARITY_VISITS = 1;

    private const POPULARITY_PLAYING = 3;

    public function __construct(
        private IgdbClient $client,
        private IgdbPayload $payload,
        private IgdbPlayFilters $playFilters,
    ) {}

    /**
     * @return list<MediaSummary>
     */
    public function trending(string $locale): array
    {
        return $this->byPopularity(self::POPULARITY_VISITS);
    }

    /**
     * @return list<string>
     */
    public function discoverableTypes(): array
    {
        return ['game'];
    }

    /**
     * @return list<MediaSummary>
     */
    public function popular(string $type, string $locale): array
    {
        $this->payload->assertGame($type);

        return $this->byPopularity(self::POPULARITY_PLAYING);
    }

    /**
     * @return list<MediaSummary>
     */
    public function upcoming(string $type, string $locale): array
    {
        $this->payload->assertGame($type);

        // hypes > 0 keeps datestuffed shovelware out of the section.
        $data = $this->client->query(
            'games',
            'fields '.IgdbPayload::SUMMARY_FIELDS.'; '
            .'where first_release_date >= '.$this->payload->startOfTodayUtc().' & cover != null & hypes > 0 & '.GameType::clause(GameType::trackable()).'; '
            .'sort first_release_date asc; limit 20;',
            $this->payload->ttl('listing'),
            requireResults: true,
        );

        return array_map(fn (array $game): MediaSummary => $this->payload->summary($game), Payload::rows($data));
    }

    /**
     * @return list<GenreOption>
     */
    public function genres(string $type, string $locale): array
    {
        $this->payload->assertGame($type);

        $data = $this->client->query('genres', 'fields name; sort name asc; limit 50;', $this->payload->ttl('listing'), requireResults: true);

        $genres = [];

        foreach ($data as $genre) {
            $ref = Payload::id($genre['id'] ?? null);
            $name = Payload::string($genre, 'name');

            if ($ref !== null && $name !== null) {
                $genres[] = new GenreOption($ref, $name);
            }
        }

        return $genres;
    }

    /**
     * @return Paginated<MediaSummary>
     */
    public function byGenre(string $type, string $genreRef, string $locale, int $page = 1): Paginated
    {
        $this->payload->assertGame($type);

        if (! ctype_digit($genreRef)) {
            return new Paginated([], 1, 1, 0);
        }

        $page = max(1, $page);

        // A floor on total_rating_count keeps the listing to games the community
        // actually rated; the sort is the closest thing to "popular".
        // (N) is Apicalypse containment — the genres field is an array.
        $where = "genres = ({$genreRef}) & cover != null & total_rating_count > ".self::MIN_RATINGS.' & '.GameType::clause(GameType::trackable());

        $data = $this->client->query(
            'games',
            'fields '.IgdbPayload::SUMMARY_FIELDS.'; '
            ."where {$where}; sort total_rating_count desc; "
            .'limit '.IgdbPayload::LISTING_PAGE_SIZE.'; offset '.(($page - 1) * IgdbPayload::LISTING_PAGE_SIZE).';',
            $this->payload->ttl('listing'),
            requireResults: true,
        );

        return $this->payload->paginated($data, $page, $this->client->count('games', "where {$where};", $this->payload->ttl('listing')));
    }

    /**
     * @return list<string>
     */
    public function filterableTypes(): array
    {
        return ['game'];
    }

    /**
     * @param  array<string, mixed>  $requested
     * @return list<DiscoverFilter>
     */
    public function discoverFilters(string $type, string $locale, string $region, array $requested = []): array
    {
        $this->payload->assertGame($type);

        return [
            $this->genresFilter($type, $locale),
            $this->platformFilter(),
            ...$this->playFilters->filters($locale),
            DiscoverFilter::years(),
            new DiscoverFilter('sort', __('discover.sort_by'), DiscoverFilterKind::Select, $this->sorts(), default: self::DEFAULT_SORT),
        ];
    }

    /**
     * @param  array<string, string|list<string>|array{from: int|null, to: int|null}>  $filters
     * @return Paginated<MediaSummary>
     */
    public function discoverFiltered(
        string $type,
        array $filters,
        string $locale,
        string $region,
        int $page = 1,
    ): Paginated {
        $this->payload->assertGame($type);

        $page = max(1, $page);
        $clauses = ['cover != null', GameType::clause(GameType::trackable())];

        $genres = $this->payload->numericIds($filters['genres'] ?? null);

        if ($genres !== []) {
            // (N) is Apicalypse containment — genres is an array field, so
            // this reads as "any of these", not "all of these".
            $clauses[] = 'genres = ('.implode(',', $genres).')';
        }

        $platforms = $this->payload->numericIds($filters['platform'] ?? null);

        if ($platforms !== []) {
            $clauses[] = 'platforms = ('.implode(',', $platforms).')';
        }

        $clauses = [...$clauses, ...$this->playFilters->clauses($filters)];

        $years = $filters['year'] ?? null;

        if (is_array($years) && ! array_is_list($years)) {
            // IGDB dates are UTC epochs; the upper end is the first instant of
            // the next year, so the last day of the span stays inside it.
            if (($years['from'] ?? null) !== null) {
                $clauses[] = 'first_release_date >= '.gmmktime(0, 0, 0, 1, 1, (int) $years['from']);
            }

            if (($years['to'] ?? null) !== null) {
                $clauses[] = 'first_release_date < '.gmmktime(0, 0, 0, 1, 1, (int) $years['to'] + 1);
            }
        }

        $sort = $filters['sort'] ?? null;
        $sort = is_string($sort) && in_array($sort, $this->sortValues(), true) ? $sort : self::DEFAULT_SORT;

        // The quality floor belongs to the rating sorts alone: on "newest
        // first" it would hide exactly the releases the sort is asked for,
        // because nobody has rated them yet.
        if (in_array($sort, self::RATED_SORTS, true)) {
            $clauses[] = 'total_rating_count > '.self::MIN_RATINGS;
        }

        // A released game keeps the follows it gathered before launch: without
        // this the most anticipated would be last decade's hits.
        if ($sort === self::ANTICIPATED_SORT) {
            $clauses[] = '(first_release_date >= '.$this->payload->startOfTodayUtc().' | first_release_date = null) & hypes > 0';
        }

        $where = implode(' & ', $clauses);

        $data = $this->client->query(
            'games',
            'fields '.IgdbPayload::SUMMARY_FIELDS.'; '
            ."where {$where}; sort {$sort}; "
            .'limit '.IgdbPayload::LISTING_PAGE_SIZE.'; offset '.(($page - 1) * IgdbPayload::LISTING_PAGE_SIZE).';',
            $this->payload->ttl('listing'),
            // A combination of filters may legitimately match nothing, and
            // that answer deserves caching as much as any other.
            requireResults: false,
        );

        return $this->payload->paginated($data, $page, $this->client->count('games', "where {$where};", $this->payload->ttl('listing')));
    }

    /**
     * The genre dictionary as filter options. A dictionary that will not load
     * costs its own filter, not the page.
     */
    private function genresFilter(string $type, string $locale): DiscoverFilter
    {
        try {
            $genres = $this->genres($type, $locale);
        } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
            return new DiscoverFilter('genres', __('discover.genres'), DiscoverFilterKind::MultiSelect, unavailable: true);
        }

        $options = [];

        foreach ($genres as $genre) {
            $options[] = new DiscoverFilterOption($genre->ref, $genre->name);
        }

        return new DiscoverFilter('genres', __('discover.genres'), DiscoverFilterKind::MultiSelect, $options);
    }

    /**
     * Every platform IGDB knows, alphabetically. 500 is Apicalypse's ceiling
     * and comfortably more than the whole catalogue, so this is one page.
     */
    private function platformFilter(): DiscoverFilter
    {
        $label = __('discover.platform');

        try {
            $data = $this->client->query(
                'platforms',
                'fields name; sort name asc; limit 500;',
                $this->payload->ttl('listing'),
                requireResults: true,
            );
        } catch (ProviderException|ProviderRejected) {
            return new DiscoverFilter('platform', $label, DiscoverFilterKind::Select, unavailable: true);
        }

        $options = [];

        foreach ($data as $platform) {
            $ref = Payload::id($platform['id'] ?? null);
            $name = Payload::string($platform, 'name');

            if ($ref !== null && $name !== null) {
                $options[] = new DiscoverFilterOption($ref, $name);
            }
        }

        return new DiscoverFilter('platform', $label, DiscoverFilterKind::Select, $options);
    }

    /**
     * The sort axes offered. An axis the provider can run both ways carries its
     * ascending twin instead of taking a second entry of its own.
     *
     * @return list<DiscoverFilterOption>
     */
    private function sorts(): array
    {
        return [
            new DiscoverFilterOption(self::DEFAULT_SORT, __('discover.most_rated')),
            new DiscoverFilterOption('total_rating desc', __('discover.highest_rated')),
            new DiscoverFilterOption(self::ANTICIPATED_SORT, __('discover.most_anticipated')),
            new DiscoverFilterOption('first_release_date desc', __('common.release_date'), ascending: 'first_release_date asc'),
        ];
    }

    /**
     * Every sort expression the filter offers, both directions of a directional
     * one — the whitelist an incoming value is checked against, so no aged link
     * can smuggle a raw Apicalypse clause into the query.
     *
     * @return list<string>
     */
    private function sortValues(): array
    {
        $values = [];

        foreach ($this->sorts() as $option) {
            $values[] = $option->value;

            if ($option->ascending !== null) {
                $values[] = $option->ascending;
            }
        }

        return $values;
    }

    /**
     * Games ranked by one of IGDB's popularity signals. Two requests: the
     * ranking only carries game ids, the games query fills the summaries.
     *
     * @return list<MediaSummary>
     */
    private function byPopularity(int $popularityType): array
    {
        $ranking = $this->client->query(
            'popularity_primitives',
            "fields game_id; where popularity_type = {$popularityType}; sort value desc; limit 30;",
            $this->payload->ttl('listing'),
            requireResults: true,
        );

        $ids = array_values(array_filter(array_map(
            fn (array $row): int => Payload::int($row, 'game_id') ?? 0,
            $ranking,
        )));

        if ($ids === []) {
            return [];
        }

        $data = $this->client->query(
            'games',
            'fields '.IgdbPayload::SUMMARY_FIELDS.'; '
            .'where id = ('.implode(',', $ids).') & cover != null; limit 30;',
            $this->payload->ttl('listing'),
            requireResults: true,
        );

        // The games query loses the ranking order; restore it.
        $byId = [];

        foreach ($data as $game) {
            $byId[Payload::int($game, 'id') ?? 0] = $game;
        }

        $summaries = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $summaries[] = $this->payload->summary($byId[$id]);
            }
        }

        return array_slice($summaries, 0, 20);
    }
}

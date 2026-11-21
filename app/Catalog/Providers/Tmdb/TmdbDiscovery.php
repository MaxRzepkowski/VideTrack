<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\ChartOrder;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;
use App\Catalog\MatureContent;
use App\Support\Locales;

/**
 * Discover: trending, popular and upcoming shelves, a genre's listing and the
 * filtered grid.
 */
final readonly class TmdbDiscovery
{
    /** Sorts by score, which need a vote floor to mean anything. */
    private const RATED_SORTS = ['vote_average.desc'];

    private const MIN_VOTES = 200;

    /** Votes a title needs to stand in the top-rated chart; shows gather far fewer than films. */
    private const TOP_RATED_MIN_VOTES = ['movie' => 3000, 'show' => 500];

    public function __construct(
        private TmdbClient $client,
        private TmdbPayload $payload,
        private TmdbDiscoverFilters $filters,
    ) {}

    /**
     * The contract is cross-type, and so is the result — but it is built from
     * the two per-type endpoints rather than `trending/all/week`. That mixed
     * feed is one page of twenty split between movies and shows, so whichever
     * type the caller keeps ends up with a fraction of a page (shows routinely
     * land at seven). A page each gives every type a full twenty to slice.
     *
     * @return list<MediaSummary>
     */
    public function trending(string $locale): array
    {
        $results = [];

        foreach (['movie' => 'trending/movie/week', 'show' => 'trending/tv/week'] as $type => $path) {
            $data = $this->client->get($path, [
                'language' => $this->payload->language($locale),
            ], $this->payload->ttl('listing'), 'results');

            $results = [...$results, ...$this->payload->summaries($data['results'] ?? null, $type)];
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    public function discoverableTypes(): array
    {
        return ['movie', 'show'];
    }

    /**
     * @return list<MediaSummary>
     */
    public function popular(string $type, string $locale): array
    {
        return $this->listing($this->payload->typePath($type, 'movie/popular', 'tv/popular'), $type, $locale);
    }

    /**
     * @return list<MediaSummary>
     */
    public function upcoming(string $type, string $locale, MatureContent $mature): array
    {
        // Movies: TMDB's legacy movie/upcoming endpoint has a loose,
        // region-gated window that leaks already-released films, so a discover
        // query floored at today is cleaner. Crucially the floor is on
        // primary_release_date (the film's original debut), NOT release_date:
        // classics get future theatrical re-release dates that pass a
        // release_date floor while the card still shows the 1977/2019 debut
        // year, so Star Wars and Endgame would surface as "upcoming". Shows
        // have no genuine "upcoming" endpoint; tv/on_the_air (airing this week)
        // is the closest.
        if ($type === 'movie') {
            return $this->listing('discover/movie', 'movie', $locale, [
                'sort_by' => 'popularity.desc',
                'include_adult' => $mature->allowsAdult(),
                'include_video' => false,
                // Theatrical (limited | wide) only — no festival/digital noise.
                'with_release_type' => '2|3',
                'primary_release_date.gte' => today()->toDateString(),
                // The country whose release dates the user expects.
                'region' => Locales::region($locale),
            ]);
        }

        return $this->listing('tv/on_the_air', $type, $locale);
    }

    /**
     * @return Paginated<MediaSummary>
     */
    public function byGenre(string $type, string $genreRef, string $locale, MatureContent $mature, int $page): Paginated
    {
        $data = $this->client->get($this->payload->typePath($type, 'discover/movie', 'discover/tv'), [
            'language' => $this->payload->language($locale),
            'with_genres' => $genreRef,
            'sort_by' => 'popularity.desc',
            'include_adult' => $mature->allowsAdult(),
            'page' => max(1, $page),
        ], $this->payload->ttl('listing'), 'results');

        return $this->payload->paginated($data, $this->payload->summaries($data['results'] ?? null, $type));
    }

    /**
     * A chart is a discover query in a fixed order: by the number of votes,
     * or by the rating among titles with enough votes for it to mean
     * something (far more than the filter page asks: this is "the best
     * known to be good", not "good and somewhat known").
     *
     * @return Paginated<MediaSummary>
     */
    public function chart(string $type, ChartOrder $order, ?int $fromYear, ?int $toYear, string $locale, int $page = 1): Paginated
    {
        $date = $type === 'movie' ? 'primary_release_date' : 'first_air_date';

        $data = $this->client->get($this->payload->typePath($type, 'discover/movie', 'discover/tv'), [
            'language' => $this->payload->language($locale),
            // A chart is fetched once for every viewer, so it never goes past Suggestive.
            'include_adult' => false,
            'include_video' => false,
            'page' => min(max(1, $page), TmdbPayload::MAX_PAGES),
            ...($fromYear === null ? [] : ["{$date}.gte" => "{$fromYear}-01-01"]),
            ...($toYear === null ? [] : ["{$date}.lte" => "{$toYear}-12-31"]),
            ...match ($order) {
                ChartOrder::MostVoted => ['sort_by' => 'vote_count.desc'],
                ChartOrder::TopRated => ['sort_by' => 'vote_average.desc', 'vote_count.gte' => self::TOP_RATED_MIN_VOTES[$type] ?? self::MIN_VOTES],
            },
        ], $this->payload->ttl('listing'), 'page');

        $results = $data['results'] ?? [];

        return $this->payload->paginated($data, $this->payload->summaries(is_array($results) ? array_values($results) : [], $type));
    }

    /**
     * @return list<string>
     */
    public function filterableTypes(): array
    {
        return ['movie', 'show'];
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
        MatureContent $mature,
        int $page,
    ): Paginated {
        $path = $this->payload->typePath($type, 'discover/movie', 'discover/tv');
        // TMDB answers a page past its own 500-page ceiling with an empty
        // list rather than an error, so the ceiling is applied here — the
        // caller learns the real last page from `totalPages`.
        $params = [
            'language' => $this->payload->language($locale),
            'include_adult' => $mature->allowsAdult(),
            'page' => min(max(1, $page), TmdbPayload::MAX_PAGES),
            ...$this->listParams($filters),
            ...$this->rangeParams($type, $filters),
            ...$this->choiceParams($type, $filters, $region),
            ...$this->sortParams($type, $filters),
        ];

        // Unlike the browse listings, an empty result here is a legitimate
        // answer (filters do intersect to nothing), so the cache guard hangs
        // on `page` — present in every discover response, absent only from a
        // body that did not come from TMDB.
        $data = $this->client->get($path, $params, $this->payload->ttl('listing'), 'page');

        return $this->payload->paginated($data, $this->payload->summaries($data['results'] ?? null, $type));
    }

    /**
     * Genres in and out, and keywords: lists TMDB takes as "any of".
     *
     * @param  array<string, string|list<string>|array{from: int|null, to: int|null}>  $filters
     * @return array<string, string>
     */
    private function listParams(array $filters): array
    {
        $params = [];
        $genres = $filters['genres'] ?? null;

        if (is_array($genres) && array_is_list($genres) && $genres !== []) {
            // "|" is TMDB's OR; a comma would demand every genre at once.
            $params['with_genres'] = implode('|', $genres);
        }

        $excludedGenres = $filters['exclude_genres'] ?? null;

        if (is_array($excludedGenres) && array_is_list($excludedGenres) && $excludedGenres !== []) {
            $params['without_genres'] = implode('|', $excludedGenres);
        }

        $keywords = $filters['keywords'] ?? null;

        if (is_array($keywords) && array_is_list($keywords)) {
            $keywords = array_slice(array_values(array_filter($keywords, fn (mixed $value): bool => is_string($value) && preg_match('/^\d+$/', $value) === 1)), 0, 10);

            if ($keywords !== []) {
                $params['with_keywords'] = implode('|', $keywords);
            }
        }

        return $params;
    }

    /**
     * Years and runtime, either end open.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string|int>
     */
    private function rangeParams(string $type, array $filters): array
    {
        $params = [];
        $years = $filters['year'] ?? null;

        if (is_array($years) && ! array_is_list($years)) {
            // A film's own debut, not a re-release; a show's first air date.
            $key = $type === 'movie' ? 'primary_release_date' : 'first_air_date';

            if (is_int($years['from'] ?? null)) {
                $params["{$key}.gte"] = "{$years['from']}-01-01";
            }

            if (is_int($years['to'] ?? null)) {
                $params["{$key}.lte"] = "{$years['to']}-12-31";
            }
        }

        $runtime = $filters['runtime'] ?? null;

        if (is_array($runtime) && ! array_is_list($runtime)) {
            if (is_int($runtime['from'] ?? null)) {
                $params['with_runtime.gte'] = $runtime['from'];
            }

            if (is_int($runtime['to'] ?? null)) {
                $params['with_runtime.lte'] = $runtime['to'];
            }
        }

        return $params;
    }

    /**
     * One value picked from a dictionary: language, country, age rating,
     * minimum score, a show's status and kind, a streaming service. A value
     * the filter does not offer is dropped.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function choiceParams(string $type, array $filters, string $region): array
    {
        $params = [];
        $picked = fn (string $key): ?string => is_string($filters[$key] ?? null) && $filters[$key] !== '' ? $filters[$key] : null;

        $language = $picked('original_language');

        if ($language !== null && preg_match('/^[a-z]{2}$/', $language) === 1) {
            $params['with_original_language'] = $language;
        }

        $country = $picked('origin_country');

        if ($country !== null && preg_match('/^[A-Z]{2}$/', $country) === 1) {
            $params['with_origin_country'] = $country;
        }

        $certification = $picked('certification');

        if ($certification !== null && $region !== '' && $this->filters->allows($type, 'certification', $certification, $region)) {
            $params['certification'] = $certification;
            $params['certification_country'] = $region;
        }

        foreach (['rating' => 'vote_average.gte', 'status' => 'with_status', 'show_type' => 'with_type'] as $key => $param) {
            $value = $picked($key);

            if ($value !== null && $this->filters->allows($type, $key, $value, $region)) {
                $params[$param] = $value;
            }
        }

        $watchProvider = $picked('watch_provider');

        if ($watchProvider !== null && preg_match('/^\d+$/', $watchProvider) === 1) {
            $params['with_watch_providers'] = $watchProvider;
            $params['watch_region'] = $region;
            // Picking a service means "included in my subscription". Without this, Prime Video answers with its whole
            // rent-and-buy catalogue and the filter says nothing.
            $params['with_watch_monetization_types'] = 'flatrate';
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string|int>
     */
    private function sortParams(string $type, array $filters): array
    {
        $sort = $filters['sort'] ?? null;
        $sort = is_string($sort) && $this->filters->allows($type, 'sort', $sort, '') ? $sort : TmdbDiscoverFilters::DEFAULT_SORT;

        // Without a floor, "highest rated" is a list of obscurities with one
        // perfect vote.
        return in_array($sort, self::RATED_SORTS, true)
            ? ['sort_by' => $sort, 'vote_count.gte' => self::MIN_VOTES]
            : ['sort_by' => $sort];
    }

    /**
     * A one-page listing endpoint mapped to summaries of a single known
     * type (unlike trending/search, these responses carry no media_type).
     *
     * @param  array<string, string|int|bool>  $params
     * @return list<MediaSummary>
     */
    private function listing(string $path, string $type, string $locale, array $params = []): array
    {
        $data = $this->client->get($path, [
            'language' => $this->payload->language($locale),
            ...$params,
        ], $this->payload->ttl('listing'), 'results');

        return $this->payload->summaries($data['results'] ?? null, $type);
    }
}

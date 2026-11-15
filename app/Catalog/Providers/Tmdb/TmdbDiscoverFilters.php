<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Data\DiscoverFilter;
use App\Catalog\Data\DiscoverFilterOption;
use App\Catalog\Data\GenreOption;
use App\Catalog\DiscoverFilterKind;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Support\Payload;

/**
 * What the filtered Discover grid offers for a type — genres, languages,
 * countries, certifications, keywords, services, sorts — and the genre
 * dictionary behind it.
 */
final readonly class TmdbDiscoverFilters
{
    /** The resting sort order, kept out of the URL. */
    public const DEFAULT_SORT = 'popularity.desc';

    /** The tail of a region's provider list is one-title services. */
    private const MAX_WATCH_PROVIDERS = 30;

    public function __construct(
        private TmdbClient $client,
        private TmdbPayload $payload,
        private TmdbReleases $releases,
    ) {}

    /**
     * @return list<GenreOption>
     */
    public function genres(string $type, string $locale): array
    {
        $data = $this->client->get($this->payload->typePath($type, 'genre/movie/list', 'genre/tv/list'), [
            'language' => $this->payload->language($locale),
        ], $this->payload->ttl('listing'), 'genres');

        $genres = [];

        foreach (Payload::list($data['genres'] ?? null) as $genre) {
            $ref = Payload::id($genre['id'] ?? null);
            $name = Payload::string($genre, 'name');

            if ($ref !== null && $name !== null) {
                $genres[] = new GenreOption($ref, $name);
            }
        }

        return $genres;
    }

    /**
     * @param  array<string, mixed>  $requested
     * @return list<DiscoverFilter>
     */
    public function discoverFilters(string $type, string $locale, string $region, array $requested = []): array
    {
        $genres = $this->genresFilter($type, $locale);
        $filters = [
            $genres,
            new DiscoverFilter('exclude_genres', __('discover.exclude_genres'), DiscoverFilterKind::MultiSelect, $genres->options, unavailable: $genres->unavailable),
            DiscoverFilter::years(),
            new DiscoverFilter('runtime', __('common.runtime'), DiscoverFilterKind::Range, rangeMin: 0, rangeMax: 1440),
            $this->languageFilter(),
            $this->countryFilter(),
            $this->certificationFilter($type, $region),
            new DiscoverFilter('rating', __('discover.minimum_rating'), DiscoverFilterKind::Select, $this->ratingOptions()),
        ];

        // Only a show has a lifecycle; a film is either out or it is not.
        if ($type === 'show') {
            $filters[] = new DiscoverFilter('status', __('common.show_status'), DiscoverFilterKind::Select, $this->statusOptions());
            $filters[] = new DiscoverFilter('show_type', __('common.series_type'), DiscoverFilterKind::Select, $this->showTypeOptions());
        }

        $filters[] = $this->keywordFilter($requested['keywords'] ?? null);
        $filters[] = $this->watchProviderFilter($type, $locale, $region);

        $filters[] = new DiscoverFilter('sort', __('discover.sort_by'), DiscoverFilterKind::Select, $this->sorts($type), default: self::DEFAULT_SORT);

        return $filters;
    }

    /**
     * Whether a value is one this type's filter offers — the grid drops any
     * other rather than pass it to TMDB. The same options the page draws, so
     * an option added here is accepted there too.
     */
    public function allows(string $type, string $key, string $value, string $region): bool
    {
        $options = match ($key) {
            'rating' => $this->ratingOptions(),
            'status' => $type === 'show' ? $this->statusOptions() : [],
            'show_type' => $type === 'show' ? $this->showTypeOptions() : [],
            'sort' => null,
            'certification' => array_map(
                fn (string $code): DiscoverFilterOption => new DiscoverFilterOption($code, $code),
                array_keys($this->releases->certificationDictionary($type === 'movie' ? 'movie' : 'tv')[$region] ?? []),
            ),
            default => [],
        };

        if ($options === null) {
            return in_array($value, $this->sortValues($type), true);
        }

        foreach ($options as $option) {
            if ($option->value === $value) {
                return true;
            }
        }

        return false;
    }

    /** @return list<DiscoverFilterOption> */
    private function ratingOptions(): array
    {
        return [
            new DiscoverFilterOption('5', '5+'),
            new DiscoverFilterOption('6', '6+'),
            new DiscoverFilterOption('7', '7+'),
            new DiscoverFilterOption('8', '8+'),
        ];
    }

    /** @return list<DiscoverFilterOption> TMDB's `with_status` codes. */
    private function statusOptions(): array
    {
        return [
            new DiscoverFilterOption('0', __('status.returning_series')),
            new DiscoverFilterOption('1', __('status.planned')),
            new DiscoverFilterOption('2', __('status.in_production')),
            new DiscoverFilterOption('3', __('status.ended')),
            new DiscoverFilterOption('4', __('status.canceled')),
        ];
    }

    /** @return list<DiscoverFilterOption> TMDB's `with_type` codes. */
    private function showTypeOptions(): array
    {
        return [
            new DiscoverFilterOption('0', __('common.documentary')),
            new DiscoverFilterOption('1', __('common.news')),
            new DiscoverFilterOption('2', __('common.miniseries')),
            new DiscoverFilterOption('3', __('common.reality')),
            new DiscoverFilterOption('4', __('common.scripted')),
            new DiscoverFilterOption('5', __('common.talk_show')),
            new DiscoverFilterOption('6', __('common.video')),
        ];
    }

    /**
     * The genre dictionary as filter options, in TMDB's own order. A
     * dictionary that will not load costs its own filter, not the page.
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

    /** Languages TMDB accepts in `with_original_language`. */
    private function languageFilter(): DiscoverFilter
    {
        $label = __('common.original_language');

        try {
            $entries = $this->client->getList('configuration/languages', [], $this->payload->ttl('dictionary'));
        } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
            return new DiscoverFilter('original_language', $label, DiscoverFilterKind::Select, unavailable: true);
        }

        $options = [];

        foreach ($entries as $entry) {
            $code = Payload::string($entry, 'iso_639_1');
            $name = Payload::string($entry, 'english_name') ?? Payload::string($entry, 'name');

            if ($code !== null && $name !== null) {
                $options[] = new DiscoverFilterOption($code, $name);
            }
        }

        usort($options, fn (DiscoverFilterOption $a, DiscoverFilterOption $b): int => strcasecmp($a->label, $b->label));

        return new DiscoverFilter('original_language', $label, DiscoverFilterKind::Select, $options, unavailable: $options === []);
    }

    /** Production countries TMDB accepts in `with_origin_country`. */
    private function countryFilter(): DiscoverFilter
    {
        $label = __('common.country_of_origin');

        try {
            $entries = $this->client->getList('configuration/countries', [], $this->payload->ttl('dictionary'));
        } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
            return new DiscoverFilter('origin_country', $label, DiscoverFilterKind::Select, unavailable: true);
        }

        $options = [];

        foreach ($entries as $entry) {
            $code = Payload::string($entry, 'iso_3166_1');
            $name = Payload::string($entry, 'english_name');

            if ($code !== null && $name !== null) {
                $options[] = new DiscoverFilterOption($code, $name);
            }
        }

        usort($options, fn (DiscoverFilterOption $a, DiscoverFilterOption $b): int => strcasecmp($a->label, $b->label));

        return new DiscoverFilter('origin_country', $label, DiscoverFilterKind::Select, $options, unavailable: $options === []);
    }

    /** Age ratings valid for the user's region. */
    private function certificationFilter(string $type, string $region): DiscoverFilter
    {
        $label = __('common.certification');
        $dictionary = $this->releases->certificationDictionary($type === 'movie' ? 'movie' : 'tv');
        $options = [];

        foreach (array_keys($dictionary[$region] ?? []) as $code) {
            $options[] = new DiscoverFilterOption($code, $code);
        }

        return new DiscoverFilter('certification', $label, DiscoverFilterKind::Select, $options, unavailable: $dictionary === []);
    }

    /** The URL names at most ten keyword refs; each gets its immutable label. */
    private function keywordFilter(mixed $requested): DiscoverFilter
    {
        $values = is_string($requested) ? [$requested] : (is_array($requested) ? $requested : []);
        $refs = [];

        foreach ($values as $value) {
            if (is_string($value) && preg_match('/^\d+$/', $value) === 1 && ! in_array($value, $refs, true)) {
                $refs[] = $value;
            }

            if (count($refs) === 10) {
                break;
            }
        }

        $options = [];

        foreach ($refs as $ref) {
            try {
                $data = $this->client->get("keyword/{$ref}", [], $this->payload->ttl('dictionary'), 'id');
                $name = Payload::string($data, 'name');
            } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
                $name = null;
            }

            $options[] = new DiscoverFilterOption($ref, $name ?? "#{$ref}");
        }

        return new DiscoverFilter('keywords', __('discover.keyword'), DiscoverFilterKind::MultiSelect, $options, open: true);
    }

    /**
     * The streaming services worth offering in one region, by TMDB's own
     * display priority. The tail of that list is long and full of one-title
     * services, so it is cut — a region with nothing at all yields an empty
     * (but available) filter the page simply does not draw.
     */
    private function watchProviderFilter(string $type, string $locale, string $region): DiscoverFilter
    {
        $label = __('discover.streaming_service');

        try {
            $data = $this->client->get($this->payload->typePath($type, 'watch/providers/movie', 'watch/providers/tv'), [
                'language' => $this->payload->language($locale),
                'watch_region' => $region,
            ], $this->payload->ttl('listing'), 'results');
        } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
            return new DiscoverFilter('watch_provider', $label, DiscoverFilterKind::Select, unavailable: true);
        }

        $entries = Payload::list($data['results'] ?? null);

        usort($entries, fn (array $a, array $b): int => (Payload::int($a, 'display_priority') ?? 0) <=> (Payload::int($b, 'display_priority') ?? 0));

        $options = [];

        foreach (array_slice($entries, 0, self::MAX_WATCH_PROVIDERS) as $entry) {
            $ref = Payload::id($entry['provider_id'] ?? null);
            $name = Payload::string($entry, 'provider_name');

            if ($ref === null || $ref === '0' || $name === null) {
                continue;
            }

            $options[] = new DiscoverFilterOption($ref, $name);
        }

        return new DiscoverFilter('watch_provider', $label, DiscoverFilterKind::Select, $options);
    }

    /**
     * The sort axes offered per type. An axis the provider can run both ways
     * carries its ascending twin instead of taking a second entry of its own.
     *
     * @return list<DiscoverFilterOption>
     */
    private function sorts(string $type): array
    {
        $date = $type === 'movie' ? 'primary_release_date' : 'first_air_date';

        $sorts = [
            new DiscoverFilterOption(self::DEFAULT_SORT, __('common.popularity')),
            new DiscoverFilterOption('vote_average.desc', __('discover.highest_rated')),
            // The one axis worth running both ways: newest first or oldest
            // first. TMDB takes `.asc` on every field, but ascending popularity
            // or revenue is a list of titles nobody has heard of.
            new DiscoverFilterOption($date.'.desc', __('common.release_date'), ascending: $date.'.asc'),
        ];

        if ($type === 'movie') {
            $sorts[] = new DiscoverFilterOption('revenue.desc', __('discover.box_office'));
        }

        return $sorts;
    }

    /**
     * Every `sort_by` the filter offers, both directions of a directional one.
     *
     * @return list<string>
     */
    public function sortValues(string $type): array
    {
        $values = [];

        foreach ($this->sorts($type) as $option) {
            $values[] = $option->value;

            if ($option->ascending !== null) {
                $values[] = $option->ascending;
            }
        }

        return $values;
    }
}

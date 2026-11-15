<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;
use App\Catalog\Department;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Catalog\TitleStatus;
use App\Support\Locales;
use App\Support\Payload;
use LogicException;

/**
 * Reading TMDB's payload shapes: the summary of a title or a person, a rating,
 * a year, a translated field, a ref of a season or an episode, the request
 * language and cache lifetimes — what every part of the provider shares.
 */
final readonly class TmdbPayload
{
    /** Titles a person's card names under their name. */
    private const KNOWN_FOR_SHOWN = 3;

    /** How many sub-requests TMDB takes in one `append_to_response`. */
    public const APPEND_LIMIT = 20;

    /** TMDB serves at most 500 pages of any listing. */
    public const MAX_PAGES = 500;

    /** Images a title's Photos section shows per kind. */
    private const GALLERY_SIZE = 30;

    /** TMDB's crew departments (configuration/jobs). */
    private const DEPARTMENTS = [
        'Acting' => Department::Acting,
        'Directing' => Department::Directing,
        'Writing' => Department::Writing,
        'Production' => Department::Production,
        'Camera' => Department::Camera,
        'Editing' => Department::Editing,
        'Sound' => Department::Sound,
        'Art' => Department::Art,
        'Costume & Make-Up' => Department::CostumeMakeUp,
        'Visual Effects' => Department::VisualEffects,
        'Lighting' => Department::Lighting,
        'Crew' => Department::Crew,
    ];

    /** TMDB's production statuses of films and shows. */
    private const STATUSES = [
        'Rumored' => TitleStatus::Rumored,
        'Planned' => TitleStatus::Planned,
        'In Production' => TitleStatus::InProduction,
        'Post Production' => TitleStatus::PostProduction,
        'Pilot' => TitleStatus::Pilot,
        'Released' => TitleStatus::Released,
        'Returning Series' => TitleStatus::ReturningSeries,
        'Ended' => TitleStatus::Ended,
        'Canceled' => TitleStatus::Canceled,
    ];

    public function code(): string
    {
        return 'tmdb';
    }

    /**
     * Maps raw listing results of a single known type to summaries, for a
     * curated shelf (trending, discover, similar): a result without a poster
     * is left out.
     *
     * @return list<MediaSummary>
     */
    public function summaries(mixed $results, string $type): array
    {
        return array_values(array_filter(
            $this->everySummary($results, $type),
            fn (MediaSummary $summary): bool => $summary->posterPath !== null,
        ));
    }

    /**
     * Maps raw results of a single known type to summaries, every one of
     * them: a whole body of work (a collection's parts) keeps the entries
     * TMDB has no poster for yet.
     *
     * A result without an id names no title and is left out.
     *
     * @return list<MediaSummary>
     */
    public function everySummary(mixed $results, string $type): array
    {
        $summaries = [];

        foreach (Payload::rows($results) as $result) {
            $summaries[] = $type === 'movie' ? $this->movieSummary($result) : $this->showSummary($result);
        }

        return $summaries;
    }

    /**
     * The id of a title or a person as a ref. Every entry TMDB serves has one,
     * so an answer without it is not one we can read.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function ref(array $data): string
    {
        return Payload::id($data['id'] ?? null) ?? throw ProviderException::missingId('TMDB');
    }

    public function typePath(string $type, string $moviePath, string $showPath): string
    {
        return match ($type) {
            'movie' => $moviePath,
            'show' => $showPath,
            default => throw new LogicException("TMDB serves no listing for type [{$type}]."),
        };
    }

    /**
     * A numbered page out of any TMDB listing response. Pages past
     * MAX_PAGES are never served, so the count stops there.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<MediaSummary>  $items
     * @return Paginated<MediaSummary>
     */
    public function paginated(array $data, array $items): Paginated
    {
        return new Paginated(
            items: $items,
            page: max(1, Payload::int($data, 'page') ?? 1),
            totalPages: min(max(1, Payload::int($data, 'total_pages') ?? 1), self::MAX_PAGES),
            total: max(0, Payload::int($data, 'total_results') ?? 0),
        );
    }

    /**
     * A person row (`search/person`, `person/popular`) as a `person`-typed
     * summary: the profile stands in for the poster, "known for" titles ride
     * along as the card's subtitle.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function personSummary(array $data): MediaSummary
    {
        $knownFor = [];

        foreach (Payload::list($data['known_for'] ?? null) as $entry) {
            $title = Payload::string($entry, 'title') ?? Payload::string($entry, 'name');

            if ($title !== null) {
                $knownFor[] = $title;
            }
        }

        return new MediaSummary(
            type: CreatorKind::PERSON_CARD,
            ref: $this->ref($data),
            provider: $this->code(),
            title: Payload::text($data, 'name'),
            posterPath: Payload::string($data, 'profile_path'),
            extra: [
                'knownForDepartment' => $this->knownForDepartment($data)?->value,
                'knownFor' => array_slice($knownFor, 0, self::KNOWN_FOR_SHOWN),
            ],
        );
    }

    /**
     * A crew entry's department; one TMDB has not listed yet is crew.
     *
     * @param  array<array-key, mixed>  $entry
     */
    public function department(array $entry): Department
    {
        return self::DEPARTMENTS[Payload::string($entry, 'department') ?? ''] ?? Department::Crew;
    }

    /** @param  array<array-key, mixed>  $data */
    public function knownForDepartment(array $data): ?Department
    {
        return self::DEPARTMENTS[Payload::string($data, 'known_for_department') ?? ''] ?? null;
    }

    /** @param  array<array-key, mixed>  $data */
    public function status(array $data): ?TitleStatus
    {
        return self::STATUSES[Payload::string($data, 'status') ?? ''] ?? null;
    }

    /**
     * A translated field resolved from the appended `translations` payload,
     * walking viewer's language → English → the work's original language. The
     * value TMDB already localized at the root wins when it is there at all;
     * everything below it is the answer to "the localized one is empty".
     *
     * Only resolved strings leave this method — the translations array itself
     * never reaches metadata.
     *
     * @param  array<array-key, mixed>  $data  the full details payload
     * @param  string  $field  a key inside translations[].data (overview, tagline, biography, …)
     */
    public function translated(array $data, string $field, string $locale): ?string
    {
        $root = Payload::string($data, $field);

        if ($root !== null) {
            return $root;
        }

        $byLanguage = [];

        foreach (Payload::list(data_get($data, 'translations.translations')) as $row) {
            $language = Payload::string($row, 'iso_639_1');
            $value = Payload::string(Payload::map($row['data'] ?? null), $field);

            // A language arrives once per region (pt-BR, pt-PT); the first
            // regional variant carrying text answers for the language.
            if ($language !== null && $value !== null && ! isset($byLanguage[$language])) {
                $byLanguage[$language] = $value;
            }
        }

        foreach ([Locales::code($locale), 'en', Payload::string($data, 'original_language')] as $code) {
            if ($code !== null && isset($byLanguage[$code])) {
                return $byLanguage[$code];
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function movieSummary(array $data): MediaSummary
    {
        return new MediaSummary(
            type: 'movie',
            ref: $this->ref($data),
            provider: $this->code(),
            title: Payload::text($data, 'title'),
            year: $this->yearOf($data, 'release_date'),
            posterPath: Payload::string($data, 'poster_path'),
            backdropPath: Payload::string($data, 'backdrop_path'),
            communityRating: $this->rating($data),
            releaseDate: Payload::string($data, 'release_date'),
            votes: Payload::positiveInt($data, 'vote_count'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function showSummary(array $data): MediaSummary
    {
        return new MediaSummary(
            type: 'show',
            ref: $this->ref($data),
            provider: $this->code(),
            title: Payload::text($data, 'name'),
            year: $this->yearOf($data, 'first_air_date'),
            posterPath: Payload::string($data, 'poster_path'),
            backdropPath: Payload::string($data, 'backdrop_path'),
            communityRating: $this->rating($data),
            releaseDate: Payload::string($data, 'first_air_date'),
            votes: Payload::positiveInt($data, 'vote_count'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public function imagePaths(array $data, string $collection): array
    {
        $paths = [];

        foreach (Payload::list(data_get($data, "images.{$collection}")) as $image) {
            $path = Payload::string($image, 'file_path');

            if ($path !== null && $this->inGallery($image)) {
                $paths[] = $path;
            }

            if (count($paths) === self::GALLERY_SIZE) {
                break;
            }
        }

        return $paths;
    }

    /**
     * The gallery and the logo show English and language-free images. A
     * details request also asks for every UI language's posters, which only
     * pick the title's poster per language (TmdbLocalizedTitles).
     *
     * @param  array<array-key, mixed>  $image
     */
    public function inGallery(array $image): bool
    {
        $language = $image['iso_639_1'] ?? null;

        return $language === null || $language === 'en';
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     */
    public function externalLinks(array $data, string $imdbKind, ?string $tmdbKind = null): array
    {
        $links = [];
        $homepage = Payload::string($data, 'homepage');
        $id = Payload::id($data['id'] ?? null);

        if ($homepage !== null) {
            $links['homepage'] = $homepage;
        }

        // The item's own TMDB page (movie / tv / person). Episodes have no
        // simple by-id URL, so their caller passes no $tmdbKind.
        if ($tmdbKind !== null && $id !== null) {
            $links['tmdb'] = "https://www.themoviedb.org/{$tmdbKind}/{$id}";
        }

        $imdbId = data_get($data, 'external_ids.imdb_id') ?? data_get($data, 'imdb_id');

        if (is_string($imdbId) && $imdbId !== '') {
            $links['imdb'] = "https://www.imdb.com/{$imdbKind}/{$imdbId}/";
        }

        // The rest of the TMDB external_ids, each with its URL template — the
        // frontend renders one dedicated icon per service.
        $sites = [
            'instagram' => ['instagram_id', 'https://www.instagram.com/%s/'],
            'facebook' => ['facebook_id', 'https://www.facebook.com/%s'],
            'x' => ['twitter_id', 'https://x.com/%s'],
            'tiktok' => ['tiktok_id', 'https://www.tiktok.com/@%s'],
            'youtube' => ['youtube_id', 'https://www.youtube.com/%s'],
            'wikidata' => ['wikidata_id', 'https://www.wikidata.org/wiki/%s'],
        ];

        foreach ($sites as $key => [$field, $template]) {
            $id = data_get($data, "external_ids.{$field}");

            if (is_string($id) && $id !== '') {
                $links[$key] = sprintf($template, $id);
            }
        }

        return $links;
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function parseSeasonRef(string $ref): array
    {
        $parts = explode(':', $ref);

        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            throw new ItemNotFoundException("Malformed TMDB season ref [{$ref}].");
        }

        return [$parts[0], (int) $parts[1]];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    public function parseEpisodeRef(string $ref): array
    {
        $parts = explode(':', $ref);

        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
            throw new ItemNotFoundException("Malformed TMDB episode ref [{$ref}].");
        }

        return [$parts[0], (int) $parts[1], (int) $parts[2]];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function rating(array $data): ?float
    {
        $average = Payload::float($data, 'vote_average') ?? 0.0;

        return $average > 0 ? round($average, 1) : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function yearOf(array $data, string $key): ?int
    {
        return Payload::yearOf(Payload::string($data, $key));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function blank(array $data, string $key): bool
    {
        return Payload::string($data, $key) === null;
    }

    public function isEnglish(string $locale): bool
    {
        return $this->language($locale) === 'en-US';
    }

    public function language(string $locale): string
    {
        return Locales::language($locale);
    }

    public function ttl(string $kind): int
    {
        return config()->integer("tracker.tmdb.cache.{$kind}");
    }
}

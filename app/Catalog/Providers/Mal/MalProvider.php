<?php

namespace App\Catalog\Providers\Mal;

use App\Catalog\Contracts\MetadataProvider;
use App\Catalog\Contracts\SupportsTrending;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\ItemNotFoundException;
use App\Catalog\Matching;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Catalog\TitleStatus;
use App\Support\Payload;
use LogicException;

/**
 * MyAnimeList implementation of the metadata contracts — the `manga` module.
 * Manga are flat (no hierarchy); refs are MAL numeric
 * manga ids. Light novels are filtered out — prose belongs to the books
 * module. MAL serves no genre browsing or upcoming listings in API v2, so
 * the provider stops at search + details + trending (ranking by
 * popularity). Covers are absolute MAL CDN URLs (`mediaImage` passes
 * `http…` through).
 */
class MalProvider implements MetadataProvider, SupportsTrending
{
    /** Below this length a refused search is an empty one. */
    private const SHORT_QUERY = 3;

    /** Covers kept for the gallery. */
    private const POSTERS_KEPT = 10;

    /** Genres a manga shows. */
    private const GENRES_KEPT = 8;

    /** Fields shared by every manga listing (search, ranking). */
    private const SUMMARY_FIELDS = 'id,title,main_picture,alternative_titles,start_date,mean,media_type,num_chapters';

    /** MAL media_type values that are prose, not comics. */
    private const NOVEL_TYPES = ['novel', 'light_novel'];

    public function __construct(private readonly MalClient $client) {}

    public function code(): string
    {
        return 'mal';
    }

    public function search(string $query, string $locale): array
    {
        try {
            $data = $this->client->get(
                '/manga',
                ['q' => $query, 'limit' => 20, 'fields' => self::SUMMARY_FIELDS],
                $this->ttl('search'),
                requireKey: 'data',
            );
        } catch (ProviderRejected $refusal) {
            // MAL is known to turn away a very short `q`; typing "Ao" finds nothing yet, it is not an error.
            if (mb_strlen($query) < self::SHORT_QUERY) {
                return [];
            }

            throw $refusal;
        }

        return $this->nodeSummaries($data);
    }

    public function details(string $type, string $ref, string $locale, ?string $region = null): MediaDetails
    {
        // The type registry routes only its own type here; anything else is a bug.
        if ($type !== 'manga') {
            throw new LogicException("MyAnimeList does not serve details for type [{$type}].");
        }

        // A ref from an import or a webhook may be anything; a bad one names nothing.
        if (! ctype_digit($ref)) {
            throw new ItemNotFoundException("MyAnimeList has no manga [{$ref}].");
        }

        $data = $this->client->get(
            "/manga/{$ref}",
            ['fields' => self::SUMMARY_FIELDS.',synopsis,status,genres,'
                .'num_volumes,num_list_users,num_scoring_users,authors{first_name,last_name},serialization{name},pictures'],
            $this->ttl('details'),
            requireKey: 'id',
        );

        $id = Payload::int($data, 'id') ?? throw new ItemNotFoundException("MyAnimeList returned no manga for [{$ref}].");

        $genres = Payload::names($data['genres'] ?? null);

        $summary = $this->summary($data);
        $original = $this->nativeTitle($data);
        $original = $original !== null && Matching::sameTitle($original, $summary->title) ? null : $original;

        $posters = [];

        foreach (Payload::list($data['pictures'] ?? null) as $picture) {
            $url = $this->pictureUrl($picture);

            if ($url !== null) {
                $posters[] = $url;
            }
        }

        return new MediaDetails(
            summary: $summary,
            overview: Payload::string($data, 'synopsis'),
            genres: array_slice($genres, 0, self::GENRES_KEPT),
            status: $this->publicationStatus($data),
            externalLinks: ['mal' => 'https://myanimelist.net/manga/'.$id],
            extra: [
                'chapters' => Payload::positiveInt($data, 'num_chapters'),
                'volumes' => Payload::positiveInt($data, 'num_volumes'),
                // Manga / Manhwa / Manhua / One-shot — MAL mixes formats in
                // one catalogue; the format chip disambiguates.
                'mangaFormat' => $this->format($data),
                'magazine' => Payload::string(Payload::map(data_get($data, 'serialization.0.node')), 'name'),
                'readersCount' => Payload::positiveInt($data, 'num_list_users'),
                'ratingsCount' => Payload::positiveInt($data, 'num_scoring_users'),
                // The title in the manga's own script (Japanese, Korean,
                // Chinese) is its original, as a film's is; MAL's own
                // romanised one is just another name it goes by.
                'originalTitle' => $original,
                'alternativeTitles' => array_values(array_filter($summary->alternativeTitles, fn (string $name): bool => $name !== $original)) ?: null,
            ],
            posters: array_slice($posters, 0, self::POSTERS_KEPT),
        );
    }

    public function trending(string $locale): array
    {
        // MAL has no activity-based trending for manga; the popularity
        // ranking (members count) is the closest stable signal.
        $data = $this->client->get(
            '/manga/ranking',
            ['ranking_type' => 'bypopularity', 'limit' => 30, 'fields' => self::SUMMARY_FIELDS],
            $this->ttl('listing'),
            requireKey: 'data',
        );

        return array_slice($this->nodeSummaries($data), 0, 20);
    }

    /**
     * Summaries out of a MAL list payload (`data[].node`): novels drop out
     * (books module territory), covers are required.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<MediaSummary>
     */
    private function nodeSummaries(array $data): array
    {
        $summaries = [];

        foreach (Payload::list($data['data'] ?? null) as $entry) {
            $node = Payload::map($entry['node'] ?? null);

            if (Payload::id($node['id'] ?? null) === null || $this->pictureUrl(Payload::map($node['main_picture'] ?? null)) === null) {
                continue;
            }

            if (in_array($node['media_type'] ?? '', self::NOVEL_TYPES, true)) {
                continue;
            }

            $summaries[] = $this->summary($node);
        }

        return $summaries;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function summary(array $data): MediaSummary
    {
        $releaseDate = $this->startDate($data);
        $title = $this->shownTitle($data);

        return new MediaSummary(
            type: 'manga',
            ref: Payload::id($data['id'] ?? null) ?? throw ProviderException::missingId('MyAnimeList'),
            provider: $this->code(),
            title: $title,
            year: Payload::yearOf($releaseDate),
            posterPath: $this->pictureUrl(Payload::map($data['main_picture'] ?? null)),
            communityRating: (($mean = Payload::float($data, 'mean') ?? 0.0) > 0) ? round($mean, 1) : null,
            releaseDate: $releaseDate,
            extra: array_filter(['chapters' => Payload::positiveInt($data, 'num_chapters')]),
            progressTotal: Payload::positiveInt($data, 'num_chapters'),
            authors: $this->authors($data),
            alternativeTitles: $this->otherTitles($data, $title),
        );
    }

    /**
     * The name readers know the manga by: MAL's English title where it has
     * one, else MAL's own, usually romanised Japanese ("Shingeki no Kyojin").
     * MAL names a manga in no other language, so a reader of any language
     * gets the English one, as a title without their own version does on
     * every type.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function shownTitle(array $data): string
    {
        return Payload::string(Payload::map($data['alternative_titles'] ?? null), 'en') ?? Payload::text($data, 'title');
    }

    /**
     * The title in the manga's own script. MAL keeps it under `ja` whatever
     * the language: a manhwa's Korean title sits there too.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function nativeTitle(array $data): ?string
    {
        return Payload::string(Payload::map($data['alternative_titles'] ?? null), 'ja');
    }

    /**
     * Every other name MAL knows the manga by, for placing it by name: its own
     * title, the native one and the synonyms, each once, none that is the
     * shown one written another way.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function otherTitles(array $data, string $shown): array
    {
        $candidates = [
            Payload::string($data, 'title'),
            $this->nativeTitle($data),
            ...Payload::texts(Payload::map($data['alternative_titles'] ?? null)['synonyms'] ?? null),
        ];
        $titles = [];

        foreach ($candidates as $name) {
            if ($name === null || Matching::sameTitle($name, $shown)) {
                continue;
            }

            if (! in_array($name, $titles, true) && array_filter($titles, fn (string $kept): bool => Matching::sameTitle($kept, $name)) === []) {
                $titles[] = $name;
            }
        }

        return $titles;
    }

    /**
     * Author display names ("First Last"), deduplicated.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function authors(array $data): array
    {
        $names = [];

        foreach (Payload::list($data['authors'] ?? null) as $author) {
            $node = Payload::map($author['node'] ?? null);
            $name = trim(Payload::text($node, 'first_name').' '.Payload::text($node, 'last_name'));

            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * MAL start_date may be a full date, year-month or bare year — pad the
     * short forms so the app always sees Y-m-d.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function startDate(array $data): ?string
    {
        $date = Payload::string($data, 'start_date');

        return match (true) {
            $date === null => null,
            strlen($date) === 4 => "{$date}-01-01",
            strlen($date) === 7 => "{$date}-01",
            default => $date,
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function format(array $data): ?string
    {
        // A code the page names in the reader's language, not a label.
        return Payload::enum($data, 'media_type', MangaFormat::class)?->value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function publicationStatus(array $data): ?TitleStatus
    {
        return match ($data['status'] ?? null) {
            'finished' => TitleStatus::Finished,
            'currently_publishing' => TitleStatus::Publishing,
            'not_yet_published' => TitleStatus::NotYetPublished,
            'on_hiatus' => TitleStatus::OnHiatus,
            'discontinued' => TitleStatus::Discontinued,
            default => null,
        };
    }

    /**
     * Cover URL out of a main_picture / pictures object, largest size first.
     *
     * @param  array<array-key, mixed>  $picture
     */
    private function pictureUrl(array $picture): ?string
    {
        $url = $picture['large'] ?? $picture['medium'] ?? null;

        return is_string($url) && str_starts_with($url, 'http') ? $url : null;
    }

    private function ttl(string $kind): int
    {
        return config()->integer("tracker.mal.cache.{$kind}");
    }
}

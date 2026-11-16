<?php

namespace App\Catalog\Providers\Mal;

use App\Catalog\Contracts\MetadataProvider;
use App\Catalog\Contracts\SupportsTrending;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\RankingPosition;
use App\Catalog\ItemNotFoundException;
use App\Catalog\Matching;
use App\Catalog\ProviderRejected;
use App\Catalog\RankingKind;
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

    /** Fields shared by every manga listing (search, ranking, the recommendations on a page). */
    private const SUMMARY_FIELDS = 'id,title,main_picture,alternative_titles,start_date,mean,media_type,num_chapters,nsfw';

    /**
     * Listings ask MAL for adult titles too: what MAL hides by default is its
     * own call, so the catalog's rule (MangaNsfw::isAdult) decides instead.
     */
    private const ALL_RATINGS = ['nsfw' => 'true'];

    /**
     * What a manga page reads beyond its card. The recommended manga come with
     * their card fields too, so they show under the same title as their page.
     */
    private const DETAIL_FIELDS = 'end_date,synopsis,status,genres,rank,popularity,background,num_volumes,num_list_users,num_scoring_users,'
        .'authors{first_name,last_name},serialization{name},pictures,recommendations{'.self::SUMMARY_FIELDS.'}';

    /** The provider's code, on every card it makes. */
    public const CODE = 'mal';

    public function __construct(
        private readonly MalClient $client,
        private readonly MalPayload $payload,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function search(string $query, string $locale): array
    {
        try {
            $data = $this->client->get(
                '/manga',
                ['q' => $query, 'limit' => 20, 'fields' => self::SUMMARY_FIELDS, ...self::ALL_RATINGS],
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

        return $this->payload->cards(Payload::list($data['data'] ?? null));
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
            ['fields' => self::SUMMARY_FIELDS.','.self::DETAIL_FIELDS],
            $this->ttl('details'),
            requireKey: 'id',
        );

        $id = Payload::int($data, 'id') ?? throw new ItemNotFoundException("MyAnimeList returned no manga for [{$ref}].");

        $genres = Payload::names($data['genres'] ?? null);

        $summary = $this->payload->card($data);
        $status = $this->payload->publicationStatus($data);
        $original = $this->payload->nativeTitle($data);
        $original = $original !== null && Matching::sameTitle($original, $summary->title) ? null : $original;

        $posters = [];

        foreach (Payload::list($data['pictures'] ?? null) as $picture) {
            $url = $this->payload->pictureUrl($picture);

            if ($url !== null) {
                $posters[] = $url;
            }
        }

        return new MediaDetails(
            summary: $summary,
            overview: Payload::string($data, 'synopsis'),
            genres: array_slice($genres, 0, self::GENRES_KEPT),
            status: $status,
            externalLinks: ['mal' => 'https://myanimelist.net/manga/'.$id],
            extra: [
                'chapters' => Payload::positiveInt($data, 'num_chapters'),
                'volumes' => Payload::positiveInt($data, 'num_volumes'),
                // Manga / Manhwa / Manhua / One-shot — MAL mixes formats in
                // one catalogue; the format chip disambiguates.
                'mangaFormat' => $this->payload->format($data),
                'magazine' => Payload::string(Payload::map(data_get($data, 'serialization.0.node')), 'name'),
                'readersCount' => Payload::positiveInt($data, 'num_list_users'),
                'ratingsCount' => Payload::positiveInt($data, 'num_scoring_users'),
                // The title in the manga's own script (Japanese, Korean,
                // Chinese) is its original, as a film's is; MAL's own
                // romanised one is just another name it goes by.
                'originalTitle' => $original,
                'alternativeTitles' => array_values(array_filter($summary->alternativeTitles, fn (string $name): bool => $name !== $original)) ?: null,
                'authorRoles' => $this->payload->authorRoles($data) ?: null,
                // The last chapter's date: with the first, the years it ran.
                'endDate' => $this->payload->paddedDate($data, 'end_date'),
                // Without an end, whether the years read "since 1989" or just "1989".
                'stillPublishing' => $status?->isRunning() ?? false,
                // Awards, sales, where it was published abroad: MAL's own notes, in English as the synopsis is.
                'background' => Payload::string($data, 'background'),
            ],
            posters: array_slice($posters, 0, self::POSTERS_KEPT),
            related: $this->recommendations($data),
            adult: $this->payload->isAdult($data),
            rankings: $this->rankings($data),
        );
    }

    public function trending(string $locale): array
    {
        // MAL has no activity-based trending for manga; the popularity
        // ranking (members count) is the closest stable signal.
        $data = $this->client->get(
            '/manga/ranking',
            ['ranking_type' => 'bypopularity', 'limit' => 30, 'fields' => self::SUMMARY_FIELDS, ...self::ALL_RATINGS],
            $this->ttl('listing'),
            requireKey: 'data',
        );

        return array_slice($this->payload->cards(Payload::list($data['data'] ?? null)), 0, 20);
    }

    /**
     * Manga readers recommend alongside this one, the most recommended first:
     * what users pair it with, as a film's recommendations are.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<MediaSummary>
     */
    private function recommendations(array $data): array
    {
        $entries = Payload::list($data['recommendations'] ?? null);
        usort($entries, fn (array $a, array $b): int => (Payload::int($b, 'num_recommendations') ?? 0) <=> (Payload::int($a, 'num_recommendations') ?? 0));

        return $this->payload->cards($entries);
    }

    /**
     * MAL's two rankings of all manga: by score (`rank`) and by members (`popularity`).
     *
     * @param  array<array-key, mixed>  $data
     * @return list<RankingPosition>
     */
    private function rankings(array $data): array
    {
        $rankings = [];

        foreach (['rank' => RankingKind::Score, 'popularity' => RankingKind::Popularity] as $key => $kind) {
            $position = Payload::positiveInt($data, $key);

            if ($position !== null) {
                $rankings[] = new RankingPosition($kind, $position);
            }
        }

        return $rankings;
    }

    private function ttl(string $kind): int
    {
        return config()->integer("tracker.mal.cache.{$kind}");
    }
}

<?php

namespace App\Catalog\Data;

use App\Catalog\TitleStatus;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * @phpstan-import-type CardExtra from MediaSummary
 *
 * @phpstan-type DetailsExtra CardExtra&array{
 *     wikidataId?: string,
 *     collection?: CollectionLink,
 *     lastEpisodeToAir?: MediaSummary|null,
 *     nextEpisodeToAir?: MediaSummary|null,
 *     ...<string, mixed>
 * }
 *
 * Full detail payload for a media position. Fields that only some types have
 * (budget, network, created by, …) live in `extra`, interpreted by the type's
 * detail view — mirrors the `metadata` JSON column of persisted items.
 */
#[TypeScript]
final readonly class MediaDetails implements JsonSerializable
{
    public function __construct(
        public MediaSummary $summary,
        public ?string $tagline = null,
        public ?string $overview = null,
        /** Minutes (movie runtime / typical episode runtime). */
        public ?int $runtime = null,
        /** @var list<string> */
        public array $genres = [],
        public ?TitleStatus $status = null,
        public ?string $originalLanguage = null,
        /** Age certification of the viewer's region, e.g. PG-13 — the chip. */
        public ?string $certification = null,
        /** @var list<CertificationData> Every country's classification of this title. */
        public array $certifications = [],
        /** @var list<ReleaseEntry> Every dated release, one row per country per kind. */
        public array $releases = [],
        /** @var list<PlatformRelease> A game's releases, one row per platform, status and region. */
        public array $platformReleases = [],
        /** @var list<KeywordData> */
        public array $keywords = [],
        /** @var array<string, string> label => url (homepage, imdb, …) */
        public array $externalLinks = [],
        /** @var CardExtra type-specific fields (budget, revenue, network, createdBy, seasonCount, …) */
        public array $extra = [],
        /** @var list<MediaSummary> seasons of a show / episodes of a season */
        public array $children = [],
        /** @var list<CreditData> */
        public array $cast = [],
        /** @var list<CreditData> */
        public array $crew = [],
        /** @var list<VideoData> */
        public array $videos = [],
        /** @var list<string> */
        public array $backdrops = [],
        /** @var list<string> */
        public array $posters = [],
        /** @var list<string> Title logos (TMDB images.logos). */
        public array $logos = [],
        /** @var list<MediaSummary> Recommendations (TMDB: user-behaviour based). */
        public array $related = [],
        /** @var list<MediaSummary> Similar titles (TMDB: genre/keyword based). */
        public array $similar = [],
        /**
         * The title's name and poster in every UI locale, keyed by locale —
         * what each reader's cards show without asking the provider again.
         * Empty from a provider whose data has no languages: the summary is
         * then the one version for everyone.
         *
         * @var array<string, LocalizedTitle>
         */
        public array $localizedTitles = [],
        /** The title's Wikidata item, the bridge to the book or manga it adapts. */
        public ?string $wikidataId = null,
        /** The collection a title belongs to (a film saga, a game or book series). */
        public ?CollectionLink $collection = null,
        /** A show's most recently aired episode, as its card. */
        public ?MediaSummary $lastEpisodeToAir = null,
        /** A show's next episode to air, as its card. */
        public ?MediaSummary $nextEpisodeToAir = null,
        /** Filed by the provider as adult material: never put forward next to another title. */
        public bool $adult = false,
        /** @var list<RankingPosition> where the title stands in its provider's rankings, as the page shows them */
        public array $rankings = [],
    ) {}

    /**
     * A show's last aired and next airing episodes, whichever it has.
     *
     * @return list<MediaSummary>
     */
    public function airingEpisodes(): array
    {
        return array_values(array_filter([$this->lastEpisodeToAir, $this->nextEpisodeToAir]));
    }

    /**
     * Every card the page shows: the title, its children, related and
     * similar titles, and the summaries a provider keeps in `extra` (a show's
     * next or last aired episode), stored directly or in a list.
     *
     * @return list<MediaSummary>
     */
    public function cards(): array
    {
        $cards = [$this->summary, ...$this->children, ...$this->airingEpisodes(), ...$this->related, ...$this->similar];

        foreach ($this->extra as $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item instanceof MediaSummary) {
                    $cards[] = $item;
                }
            }
        }

        return $cards;
    }

    /**
     * One bag on the wire, as pages and the API read it (types/extras.ts):
     * the card's fields first, the details' own over them — a provider that
     * sets a key in both (an episode's season) sets one value — and the
     * typed fields where they always rode.
     *
     * @return DetailsExtra
     */
    public function wireExtra(): array
    {
        return [
            ...$this->summary->wireExtra(),
            ...$this->extra,
            ...array_filter([
                'wikidataId' => $this->wikidataId,
                'collection' => $this->collection,
            ], fn (mixed $value): bool => $value !== null),
            ...($this->airingEpisodes() === [] ? [] : [
                'lastEpisodeToAir' => $this->lastEpisodeToAir,
                'nextEpisodeToAir' => $this->nextEpisodeToAir,
            ]),
        ];
    }

    /**
     * The card's fields, then the page's.
     *
     * @return array{
     *     type: string,
     *     ref: string,
     *     provider: string,
     *     title: string,
     *     year: int|null,
     *     posterPath: string|null,
     *     backdropPath: string|null,
     *     communityRating: float|null,
     *     releaseDate: string|null,
     *     releaseTime: string|null,
     *     number: int|null,
     *     totalRuntime: int|null,
     *     progressTotal: int|null,
     *     tagline: string|null,
     *     overview: string|null,
     *     runtime: int|null,
     *     genres: list<string>,
     *     status: value-of<TitleStatus>|null,
     *     originalLanguage: string|null,
     *     certification: string|null,
     *     certifications: list<CertificationData>,
     *     releases: list<ReleaseEntry>,
     *     platformReleases: list<PlatformRelease>,
     *     keywords: list<KeywordData>,
     *     externalLinks: array<string, string>,
     *     extra: DetailsExtra,
     *     children: list<MediaSummary>,
     *     cast: list<CreditData>,
     *     crew: list<CreditData>,
     *     videos: list<VideoData>,
     *     backdrops: list<string>,
     *     posters: list<string>,
     *     logos: list<string>,
     *     related: list<MediaSummary>,
     *     similar: list<MediaSummary>,
     *     rankings: list<RankingPosition>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            ...$this->summary->jsonSerialize(),
            'tagline' => $this->tagline,
            'overview' => $this->overview,
            'runtime' => $this->runtime,
            'genres' => $this->genres,
            'status' => $this->status?->value,
            'originalLanguage' => $this->originalLanguage,
            'certification' => $this->certification,
            'certifications' => $this->certifications,
            'releases' => $this->releases,
            'platformReleases' => $this->platformReleases,
            'keywords' => $this->keywords,
            'externalLinks' => $this->externalLinks,
            'extra' => $this->wireExtra(),
            'children' => $this->children,
            'cast' => $this->cast,
            'crew' => $this->crew,
            'videos' => $this->videos,
            'backdrops' => $this->backdrops,
            'posters' => $this->posters,
            'logos' => $this->logos,
            'related' => $this->related,
            'similar' => $this->similar,
            'rankings' => $this->rankings,
        ];
    }
}

<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\EpisodeBadge;
use App\Catalog\EpisodeType;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Support\Locales;
use App\Support\Payload;

/**
 * Seasons and episodes: their pages, with the neighbours a page links to.
 */
final readonly class TmdbEpisodes
{
    public function __construct(
        private TmdbClient $client,
        private TmdbPayload $payload,
        private TmdbCredits $credits,
        private TmdbTitleParts $parts,
        private TmdbTitles $titles,
    ) {}

    public function seasonDetails(string $ref, string $locale, string $region): MediaDetails
    {
        [$showRef, $seasonNumber] = $this->payload->parseSeasonRef($ref);

        $data = $this->titles->fetchDetails("tv/{$showRef}/season/{$seasonNumber}", $locale, [
            // aggregate_credits carries the season's cast across its episodes,
            // and watch/providers carries season-specific availability, both
            // for free on the same request.
            'append_to_response' => 'videos,aggregate_credits,watch/providers',
        ]);

        // The capability resolver reads the canonical child endpoint. Prime
        // that cache from the appended body so it cannot cost a second HTTP
        // request on this page. A body without the key leaves the resolver to
        // ask the dedicated endpoint itself.
        if (is_array($data['watch/providers'] ?? null)) {
            $this->client->prime(
                "tv/{$showRef}/season/{$seasonNumber}/watch/providers",
                [],
                $this->payload->ttl('details'),
                $data['watch/providers'],
            );
        }

        // The hero, the back-link and the season navigation need the parent show.
        $show = $this->titles->showDetails($showRef, $locale, $region);

        [$cast, $crew] = $this->credits->mapAggregateCredits($data);

        $lastSeasonOfEndedShow = $this->titles->lastSeasonOfEndedShow($seasonNumber, $show->extra['seasonCount'] ?? null, $show->status);

        $episodes = [];

        $rawEpisodes = Payload::list($data['episodes'] ?? null);

        foreach ($rawEpisodes as $index => $episode) {
            $episodes[] = $this->parts->episodeSummary($episode, $showRef, $seasonNumber, opensSeason: $index === 0, closesEndedShow: $lastSeasonOfEndedShow);
        }

        // Same air-date hole as in showDetails: fall back to the first
        // episode, which is already in this payload.
        $airDate = Payload::string($data, 'air_date')
            ?? ($episodes[0]->releaseDate ?? null);

        return new MediaDetails(
            summary: new MediaSummary(
                type: 'season',
                ref: $ref,
                provider: $this->payload->code(),
                title: Payload::text($data, 'name'),
                year: Payload::yearOf($airDate),
                posterPath: Payload::string($data, 'poster_path') ?? $show->summary->posterPath,
                backdropPath: $show->summary->backdropPath,
                communityRating: $this->payload->rating($data),
                releaseDate: $airDate,
                number: $seasonNumber,
            ),
            overview: Payload::string($data, 'overview'),
            extra: [
                'showRef' => $showRef,
                'showTitle' => $show->summary->title,
                'episodeCount' => count($episodes),
                // Prev / next season (side arrows); Specials excluded.
                'prevSeason' => $this->seasonNeighbour($show, $seasonNumber, -1),
                'nextSeason' => $this->seasonNeighbour($show, $seasonNumber, 1),
            ],
            children: $episodes,
            cast: $cast,
            crew: $crew,
            videos: $this->parts->mapVideos($data),
        );
    }

    public function episodeDetails(string $ref, string $locale, string $region): MediaDetails
    {
        [$showRef, $seasonNumber, $episodeNumber] = $this->payload->parseEpisodeRef($ref);

        // Guest stars + per-episode crew + external ids + stills all ride the
        // one episode request (credits.cast merely repeats the show regulars).
        $data = $this->titles->fetchDetails("tv/{$showRef}/season/{$seasonNumber}/episode/{$episodeNumber}", $locale, [
            'append_to_response' => 'credits,external_ids,images,videos',
            // Stills are textless production photos, so the shared language
            // filter (English/neutral) changes nothing for this collection.
            'include_image_language' => 'en,null',
            'include_video_language' => Locales::code($locale).',en,null',
        ]);

        // The hero, the back-link and cross-season navigation need the parent.
        $show = $this->titles->showDetails($showRef, $locale, $region);

        // The season's episode list positions this episode (premiere marker)
        // and yields its in-season neighbours.
        $siblings = $this->seasonEpisodeList($showRef, $seasonNumber, $locale);
        $index = array_search($episodeNumber, array_map(fn (array $e): int => $e['episode'], $siblings), true);

        // Cast = the regulars credited for THIS episode (episode credits.cast
        // is season-scoped, so anthology seasons never leak cast between
        // seasons — the show-level cast would) plus the episode's guest stars.
        [$regulars] = $this->credits->mapCredits($data);
        [$guests, $crew] = $this->credits->mapEpisodeCredits($data);
        $cast = $this->credits->episodeCast($regulars, $guests);

        // The season's title for the breadcrumb (Show › Season N).
        $seasonTitle = null;

        foreach ($show->children as $season) {
            if ($season->number === $seasonNumber) {
                $seasonTitle = $season->title;
                break;
            }
        }

        // The badge rides the episode summary (as in seasonDetails), so the
        // premiere / finale chip renders identically wherever the summary goes.
        $episodeType = Payload::enum($data, 'episode_type', EpisodeType::class);
        $badge = EpisodeBadge::of(
            $episodeType,
            $seasonNumber,
            opensSeason: $index === 0,
            closesEndedShow: $this->titles->lastSeasonOfEndedShow($seasonNumber, $show->extra['seasonCount'] ?? null, $show->status),
        );

        $airDate = Payload::string($data, 'air_date');

        return new MediaDetails(
            summary: new MediaSummary(
                type: 'episode',
                ref: $ref,
                provider: $this->payload->code(),
                title: Payload::text($data, 'name'),
                year: Payload::yearOf($airDate),
                posterPath: $show->summary->posterPath,
                // The still is the episode's own image (cards, the page's
                // thumbnail); the hero uses the show backdrop below.
                backdropPath: Payload::string($data, 'still_path'),
                communityRating: $this->payload->rating($data),
                releaseDate: $airDate,
                number: $episodeNumber,
                seasonNumber: $seasonNumber,
                episodeType: $episodeType,
                episodeBadge: $badge,
            ),
            overview: Payload::string($data, 'overview'),
            runtime: Payload::positiveInt($data, 'runtime'),
            externalLinks: $this->payload->externalLinks($data, 'title'),
            // Page context (back-link + prev/next arrows) lives on the details
            // extra, like the season page's showRef / showTitle.
            extra: [
                'showRef' => $showRef,
                'showTitle' => $show->summary->title,
                'seasonNumber' => $seasonNumber,
                'seasonTitle' => $seasonTitle,
                // The hero backdrop (episodes/seasons have none of their own).
                'showBackdrop' => $show->summary->backdropPath,
                // The fact lives on the show's payload, which this page fetches
                // anyway — here it is narrowed to "was THIS episode in cinemas".
                'screenedTheatrically' => in_array(
                    ['seasonNumber' => $seasonNumber, 'episodeNumber' => $episodeNumber],
                    (array) ($show->extra['screenedTheatrically'] ?? []),
                    true,
                ),
                'prev' => $this->episodeNeighbour($showRef, $seasonNumber, $siblings, $index, -1, $show, $locale),
                'next' => $this->episodeNeighbour($showRef, $seasonNumber, $siblings, $index, 1, $show, $locale),
            ],
            cast: $cast,
            crew: $crew,
            // Promos for a single episode are a spoiler by nature; the page
            // renders them behind the same blur the stills use.
            videos: $this->parts->mapVideos($data),
            // Episode stills are 16:9 like backdrops, so the gallery renders
            // them through its backdrop collection (the header thumbnail among
            // them, as movie/show galleries include their primary backdrop).
            backdrops: $this->payload->imagePaths($data, 'stills'),
        );
    }

    /**
     * The episode one step (dir = -1 prev / +1 next) from the given position,
     * crossing into the adjacent aired season at the season's edges. A boundary
     * neighbour costs one cached season fetch; interior episodes cost none.
     *
     * @param  list<array{episode: int, title: string}>  $siblings
     * @param  int|false  $index  position of the current episode within $siblings
     * @return array{ref: string, seasonNumber: int, episodeNumber: int, title: string}|null
     */
    private function episodeNeighbour(string $showRef, int $seasonNumber, array $siblings, int|false $index, int $dir, MediaDetails $show, string $locale): ?array
    {
        if ($index !== false && isset($siblings[$index + $dir])) {
            $target = $siblings[$index + $dir];

            return $this->neighbourEntry($showRef, $seasonNumber, $target['episode'], $target['title']);
        }

        // At a season edge: step into the neighbouring non-empty season,
        // skipping Specials (season 0).
        $numbers = [];

        foreach ($show->children as $season) {
            if (($season->number ?? 0) > 0 && ($season->extra['episodeCount'] ?? 0) > 0) {
                $numbers[] = (int) $season->number;
            }
        }

        sort($numbers);
        $pos = array_search($seasonNumber, $numbers, true);
        $adjacent = $pos === false ? null : ($numbers[$pos + $dir] ?? null);

        if ($adjacent === null) {
            return null;
        }

        $episodes = $this->seasonEpisodeList($showRef, $adjacent, $locale);

        if ($episodes === []) {
            return null;
        }

        // prev → last episode of the previous season; next → first of the next.
        $target = $dir < 0 ? $episodes[count($episodes) - 1] : $episodes[0];

        return $this->neighbourEntry($showRef, $adjacent, $target['episode'], $target['title']);
    }

    /**
     * @return array{ref: string, seasonNumber: int, episodeNumber: int, title: string}
     */
    private function neighbourEntry(string $showRef, int $seasonNumber, int $episodeNumber, string $title): array
    {
        return [
            'ref' => "{$showRef}:{$seasonNumber}:{$episodeNumber}",
            'seasonNumber' => $seasonNumber,
            'episodeNumber' => $episodeNumber,
            'title' => $title,
        ];
    }

    /**
     * Ordered (episode number, title) pairs of a season — positions an episode
     * and locates its neighbours. Cached like any details fetch; a missing or
     * broken season never breaks the episode page.
     *
     * @return list<array{episode: int, title: string}>
     */
    private function seasonEpisodeList(string $showRef, int $seasonNumber, string $locale): array
    {
        try {
            $data = $this->client->get("tv/{$showRef}/season/{$seasonNumber}", [
                'language' => $this->payload->language($locale),
            ], $this->payload->ttl('details'));
        } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
            return [];
        }

        $episodes = [];

        foreach (Payload::list($data['episodes'] ?? null) as $episode) {
            $number = Payload::int($episode, 'episode_number');

            if ($number !== null) {
                $episodes[] = ['episode' => $number, 'title' => Payload::text($episode, 'name')];
            }
        }

        usort($episodes, fn (array $a, array $b): int => $a['episode'] <=> $b['episode']);

        return $episodes;
    }

    /**
     * The season one step (dir = -1 prev / +1 next) from the given one, among
     * the show's non-empty seasons in number order (Specials excluded, like the
     * episode boundary navigation).
     *
     * @return array{ref: string, number: int, title: string}|null
     */
    private function seasonNeighbour(MediaDetails $show, int $seasonNumber, int $dir): ?array
    {
        $seasons = array_values(array_filter(
            $show->children,
            fn (MediaSummary $season): bool => ($season->number ?? 0) > 0 && ($season->extra['episodeCount'] ?? 0) > 0,
        ));

        // showDetails already orders seasons by number.
        $numbers = array_map(fn (MediaSummary $season): int => (int) $season->number, $seasons);
        $pos = array_search($seasonNumber, $numbers, true);
        $target = $pos === false ? null : ($seasons[$pos + $dir] ?? null);

        if ($target === null) {
            return null;
        }

        return [
            'ref' => $target->ref,
            'number' => (int) $target->number,
            'title' => $target->title,
        ];
    }
}

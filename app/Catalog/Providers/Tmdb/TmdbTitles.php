<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Data\CollectionDetails;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\EpisodeBadge;
use App\Catalog\EpisodeType;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Catalog\TitleStatus;
use App\Support\Locales;
use App\Support\Payload;

/**
 * The details of a film, a show and a collection — the one fetch with its
 * English fallback, and the show's airing episodes and season premieres.
 */
final readonly class TmdbTitles
{
    public function __construct(
        private TmdbClient $client,
        private TmdbPayload $payload,
        private TmdbCredits $credits,
        private TmdbTitleParts $parts,
        private TmdbReleases $releases,
        private TmdbLocalizedTitles $localizedTitles,
    ) {}

    public function collection(string $ref, string $locale): CollectionDetails
    {
        // A collection carries a gallery like a title does, on the same
        // sub-request — no logos, TMDB serves none for a saga.
        $data = $this->fetchDetails("collection/{$ref}", $locale, [
            'append_to_response' => 'images,translations',
            'include_image_language' => 'en,null',
        ]);

        // The whole saga, posterless parts too: a collection is a body of work,
        // not a curated shelf.
        $parts = $this->payload->everySummary($data['parts'] ?? null, 'movie');

        // Release order; unreleased/undated parts go last.
        usort($parts, fn (MediaSummary $a, MediaSummary $b) => ($a->releaseDate ?? '9999') <=> ($b->releaseDate ?? '9999'));

        return new CollectionDetails(
            ref: $this->payload->ref($data),
            name: Payload::text($data, 'name'),
            overview: $this->payload->translated($data, 'overview', $locale),
            posterPath: Payload::string($data, 'poster_path'),
            backdropPath: Payload::string($data, 'backdrop_path'),
            parts: $parts,
            backdrops: $this->payload->imagePaths($data, 'backdrops'),
            posters: $this->payload->imagePaths($data, 'posters'),
        );
    }

    public function movieDetails(string $ref, string $locale, string $region): MediaDetails
    {
        $data = $this->fetchDetails("movie/{$ref}", $locale, [
            'append_to_response' => 'credits,videos,images,keywords,recommendations,similar,release_dates,external_ids,alternative_titles,translations',
            'include_image_language' => $this->localizedTitles->imageLanguages(),
            'include_video_language' => Locales::code($locale).',en,null',
        ]);

        $localized = $this->localizedTitles->of($data, 'title', 'original_title');
        $data = $this->localizedTitles->inLocale($data, $localized, $locale, 'title');

        [$cast, $crew] = $this->credits->mapCredits($data);

        $releases = $this->releases->releaseBreakdown($data);
        $certification = $this->releases->movieCertification($data, $region);

        return new MediaDetails(
            summary: $this->payload->movieSummary($data),
            tagline: $this->payload->translated($data, 'tagline', $locale),
            overview: $this->payload->translated($data, 'overview', $locale),
            runtime: Payload::positiveInt($data, 'runtime'),
            genres: Payload::names($data['genres'] ?? null),
            status: $this->payload->status($data),
            originalLanguage: Payload::string($data, 'original_language'),
            certification: $certification['code'],
            certifications: $certification['certifications'],
            releases: $releases,
            keywords: $this->parts->mapKeywords(data_get($data, 'keywords.keywords')),
            externalLinks: $this->payload->externalLinks($data, 'title', 'movie'),
            extra: [
                'budget' => Payload::positiveInt($data, 'budget'),
                'revenue' => Payload::positiveInt($data, 'revenue'),
                'studios' => $this->parts->mapStudios($data),
                // TMDB serves a film's IMDb id both at the top level and under
                // external_ids; either one lets the IMDb export key its rows by
                // Const instead of falling back to title + year.
                'imdbId' => Payload::string(Payload::map(data_get($data, 'external_ids')), 'imdb_id')
                    ?? Payload::string($data, 'imdb_id'),
                'logoPath' => $this->parts->bestLogo($data),
                'originalTitle' => $this->parts->differingOriginal($data, 'original_title', 'title'),
                // Which languages are actually spoken on screen, and which
                // countries financed it — both plain detail rows, both already
                // in the payload we pay for.
                'spokenLanguages' => $this->parts->field($data['spoken_languages'] ?? null, 'english_name'),
                'productionCountries' => $this->parts->field($data['production_countries'] ?? null, 'iso_3166_1'),
                'alternativeTitles' => $this->parts->alternativeTitles($data, 'titles', Payload::text($data, 'title'), Payload::text($data, 'original_title')),
                // The chosen chip's region and meaning, and the film's content
                // descriptors: all three describe THE selected classification,
                // which the typed `certifications` list does not model.
                'certificationRegion' => $certification['region'],
                'certificationMeaning' => $certification['meaning'],
                'certificationDescriptors' => $certification['descriptors'],
                // What the release that ends the cinema-only run says of
                // itself; its date is read off the typed `releases`.
                'homeReleaseNote' => $this->releases->homeReleaseNote($releases),
            ],
            cast: $cast,
            crew: $crew,
            videos: $this->parts->mapVideos($data),
            backdrops: $this->payload->imagePaths($data, 'backdrops'),
            posters: $this->payload->imagePaths($data, 'posters'),
            logos: $this->payload->imagePaths($data, 'logos'),
            related: $this->parts->mapRelated($data),
            similar: $this->parts->mapSimilar($data, 'movie'),
            localizedTitles: $localized,
            // Wikidata is asked about the title by this item without first
            // having to find it (see TitleItems).
            wikidataId: Payload::string(Payload::map(data_get($data, 'external_ids')), 'wikidata_id'),
            adult: $this->payload->adult($data),
            collection: $this->parts->movieCollection($data),
        );
    }

    public function showDetails(string $ref, string $locale, string $region): MediaDetails
    {
        $data = $this->fetchDetails("tv/{$ref}", $locale, [
            // aggregate_credits = the full cast across every season (not just
            // the top-billed regulars that `credits` returns).
            'append_to_response' => 'aggregate_credits,videos,images,keywords,recommendations,similar,content_ratings,external_ids,alternative_titles,translations,screened_theatrically',
            'include_image_language' => $this->localizedTitles->imageLanguages(),
            'include_video_language' => Locales::code($locale).',en,null',
        ]);

        $localized = $this->localizedTitles->of($data, 'name', 'original_name');
        $data = $this->localizedTitles->inLocale($data, $localized, $locale, 'name');

        [$cast, $crew] = $this->credits->mapAggregateCredits($data);

        $seasons = [];

        $rawSeasons = Payload::list($data['seasons'] ?? null);

        // TMDB leaves air_date empty on many anime "saga" seasons (e.g. One
        // Piece S2..S17) even though their episodes are dated. Resolve them all
        // from their first episode in ONE batched request rather than a fetch
        // per undated season, which would turn a long saga into a dozen+
        // sequential round-trips on every show/season/episode render.
        $undatedSeasons = [];

        foreach ($rawSeasons as $season) {
            if (Payload::string($season, 'air_date') === null) {
                $undatedSeasons[] = Payload::int($season, 'season_number') ?? 0;
            }
        }

        $firstEpisodeDates = $this->firstEpisodeAirDates($ref, $undatedSeasons, $locale);

        foreach ($rawSeasons as $season) {
            $number = Payload::int($season, 'season_number') ?? 0;

            $airDate = Payload::string($season, 'air_date') ?? ($firstEpisodeDates[$number] ?? null);

            $seasons[] = new MediaSummary(
                type: 'season',
                ref: $ref.':'.$number,
                provider: $this->payload->code(),
                title: Payload::text($season, 'name'),
                year: Payload::yearOf($airDate),
                posterPath: Payload::string($season, 'poster_path') ?? Payload::string($data, 'poster_path'),
                communityRating: $this->payload->rating($season),
                releaseDate: $airDate,
                number: $number,
                extra: ['episodeCount' => Payload::int($season, 'episode_count') ?? 0],
            );
        }

        // Specials (season 0) first, then seasons 1..N, the order the show page lists them in.
        usort($seasons, fn (MediaSummary $a, MediaSummary $b) => ($a->number ?? 0) <=> ($b->number ?? 0));

        $episodeRunTimes = array_filter(Payload::map($data['episode_run_time'] ?? null), is_int(...));
        $certification = $this->releases->showCertification($data, $region);

        return new MediaDetails(
            summary: $this->payload->showSummary($data),
            tagline: $this->payload->translated($data, 'tagline', $locale),
            overview: $this->payload->translated($data, 'overview', $locale),
            runtime: $episodeRunTimes === [] ? null : (int) (array_sum($episodeRunTimes) / count($episodeRunTimes)),
            genres: Payload::names($data['genres'] ?? null),
            status: $this->payload->status($data),
            originalLanguage: Payload::string($data, 'original_language'),
            certification: $certification['code'],
            certifications: $certification['certifications'],
            keywords: $this->parts->mapKeywords(data_get($data, 'keywords.results')),
            externalLinks: $this->payload->externalLinks($data, 'title', 'tv'),
            extra: [
                'createdBy' => $this->credits->people($data['created_by'] ?? null),
                'networks' => $this->parts->mapNetworks($data),
                'studios' => $this->parts->mapStudios($data),
                'seasonCount' => Payload::positiveInt($data, 'number_of_seasons'),
                'episodeCount' => Payload::positiveInt($data, 'number_of_episodes'),
                // External ids let the TVmaze air schedule (TvmazeSchedule) map
                // this show to TVmaze without a name search.
                'imdbId' => Payload::string(Payload::map(data_get($data, 'external_ids')), 'imdb_id'),
                'tvdbId' => Payload::positiveInt(Payload::map(data_get($data, 'external_ids')), 'tvdb_id'),
                'logoPath' => $this->parts->bestLogo($data),
                'originalTitle' => $this->parts->differingOriginal($data, 'original_name', 'name'),
                // origin_country arrives as bare ISO codes, spoken_languages as
                // objects; `type` is TMDB's own shape of the production
                // (Scripted, Miniseries, Reality…).
                'originCountry' => Payload::strings($data['origin_country'] ?? null),
                'spokenLanguages' => $this->parts->field($data['spoken_languages'] ?? null, 'english_name'),
                'showType' => Payload::string($data, 'type'),
                'screenedTheatrically' => $this->parts->screenedTheatrically($data),
                'alternativeTitles' => $this->parts->alternativeTitles($data, 'results', Payload::text($data, 'name'), Payload::text($data, 'original_name')),
                // As for a film: the chosen chip's region and meaning. A show
                // has no descriptors — TMDB files those per release date.
                'certificationRegion' => $certification['region'],
                'certificationMeaning' => $certification['meaning'],
                'certificationDescriptors' => [],
            ],
            children: $seasons,
            cast: $cast,
            crew: $crew,
            videos: $this->parts->mapVideos($data),
            backdrops: $this->payload->imagePaths($data, 'backdrops'),
            posters: $this->payload->imagePaths($data, 'posters'),
            logos: $this->payload->imagePaths($data, 'logos'),
            related: $this->parts->mapRelated($data),
            similar: $this->parts->mapSimilar($data, 'show'),
            localizedTitles: $localized,
            // Wikidata is asked about the title by this item without first
            // having to find it (see TitleItems).
            wikidataId: Payload::string(Payload::map(data_get($data, 'external_ids')), 'wikidata_id'),
            adult: $this->payload->adult($data),
            // The most recent and the upcoming airing, mapped as plain
            // episode summaries so the show page renders them like a
            // season's episode card.
            lastEpisodeToAir: $this->airingEpisode($data, 'last_episode_to_air', $ref),
            nextEpisodeToAir: $this->airingEpisode($data, 'next_episode_to_air', $ref),
        );
    }

    public function lastSeasonOfEndedShow(int $seasonNumber, mixed $seasonCount, ?TitleStatus $status): bool
    {
        return $seasonNumber === $seasonCount && $status?->hasEnded() === true;
    }

    /**
     * TMDB's next/last episode-to-air object mapped to an episode summary, so
     * the show page can render the upcoming and most recently aired episode
     * exactly like a season's episode card. NULL when the show carries no such
     * episode (an unreleased show has no last, an ended one has no next).
     *
     * @param  array<array-key, mixed>  $show  the tv details payload
     */
    private function airingEpisode(array $show, string $key, string $ref): ?MediaSummary
    {
        $episode = $show[$key] ?? null;

        if (! is_array($episode)) {
            return null;
        }

        $season = Payload::int($episode, 'season_number');
        $number = Payload::int($episode, 'episode_number');

        if ($season === null || $number === null) {
            return null;
        }

        $airDate = Payload::string($episode, 'air_date');
        $episodeType = Payload::enum($episode, 'episode_type', EpisodeType::class);

        // TMDB's episode_type marks finales but never a premiere, which is
        // found by position. A lone episode has no season list to stand in,
        // so the test falls back on episode_number — saga seasons with
        // absolute numbering are the rare miss.
        $badge = EpisodeBadge::of(
            $episodeType,
            $season,
            opensSeason: $number === 1,
            closesEndedShow: $this->lastSeasonOfEndedShow($season, Payload::positiveInt($show, 'number_of_seasons'), $this->payload->status($show)),
        );

        return new MediaSummary(
            type: 'episode',
            ref: $ref.':'.$season.':'.$number,
            provider: $this->payload->code(),
            title: Payload::text($episode, 'name'),
            year: Payload::yearOf($airDate),
            backdropPath: Payload::string($episode, 'still_path'),
            communityRating: $this->payload->rating($episode),
            releaseDate: $airDate,
            number: $number,
            seasonNumber: $season,
            runtime: Payload::positiveInt($episode, 'runtime'),
            episodeType: $episodeType,
            episodeBadge: $badge,
        );
    }

    /**
     * First dated episode air date for each given season — used only for
     * seasons TMDB leaves without their own air_date. Batched via
     * append_to_response (TMDB caps it at 20 sub-requests), so a saga with many
     * undated seasons costs ceil(n/20) requests instead of one per season. A
     * missing or broken season is simply absent from the map, never fatal.
     *
     * @param  list<int>  $seasonNumbers
     * @return array<int, string>
     */
    private function firstEpisodeAirDates(string $showRef, array $seasonNumbers, string $locale): array
    {
        if ($seasonNumbers === []) {
            return [];
        }

        $dates = [];

        foreach (array_chunk($seasonNumbers, TmdbPayload::APPEND_LIMIT) as $chunk) {
            try {
                $data = $this->client->get("tv/{$showRef}", [
                    'language' => $this->payload->language($locale),
                    'append_to_response' => implode(',', array_map(fn (int $n): string => "season/{$n}", $chunk)),
                ], $this->payload->ttl('details'));
            } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
                continue;
            }

            foreach ($chunk as $number) {
                foreach (Payload::list(data_get($data, "season/{$number}.episodes")) as $episode) {
                    $date = Payload::string($episode, 'air_date');

                    if ($date !== null) {
                        $dates[$number] = $date;

                        break;
                    }
                }
            }
        }

        return $dates;
    }

    /**
     * Details fetch. Callers that append `translations` resolve their empty
     * strings from that payload (see translated()) and cost one request;
     * the rest make a second request in English.
     *
     * @param  array<string, string|int|bool>  $params
     * @return array<array-key, mixed>
     */
    public function fetchDetails(string $path, string $locale, array $params): array
    {
        $data = $this->client->get($path, [
            'language' => $this->payload->language($locale),
            ...$params,
        ], $this->payload->ttl('details'));

        // A season or an episode has no `translations` to ask for: an overview
        // missing in the reader's language falls back to the English one.
        if (! array_key_exists('translations', $data) && $this->payload->blank($data, 'overview') && ! $this->payload->isEnglish($locale)) {
            $fallback = $this->client->get($path, ['language' => 'en-US'], $this->payload->ttl('details'));
            $data['overview'] = $fallback['overview'] ?? null;
            $data['tagline'] = $this->payload->blank($data, 'tagline') ? ($fallback['tagline'] ?? null) : $data['tagline'];
        }

        return $data;
    }
}

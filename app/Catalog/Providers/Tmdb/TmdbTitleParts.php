<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\CollectionLink;
use App\Catalog\Data\CreatorLink;
use App\Catalog\Data\KeywordData;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\VideoData;
use App\Catalog\EpisodeBadge;
use App\Catalog\EpisodeType;
use App\Catalog\VideoKind;
use App\Catalog\VideoSite;
use App\Support\Payload;

/**
 * The smaller parts of a title page TMDB serves alongside the details: videos,
 * alternative and original titles, networks and studios, similar and related
 * titles, the logo, the collection, keywords, field lists, theatrical
 * screening, a season's episodes.
 */
final readonly class TmdbTitleParts
{
    /** Titles a related or similar shelf shows. */
    private const SHELF_SIZE = 12;

    public function __construct(
        private TmdbPayload $payload,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<VideoData>
     */
    public function mapVideos(array $data): array
    {
        $videos = [];

        $rawVideos = Payload::list(data_get($data, 'videos.results'));

        foreach ($rawVideos as $video) {
            // The two sites TMDB's own upload form accepts, which are also the
            // two the section knows how to play. A third one would render as a
            // tile that opens a blank player, so it stays out, and so does a
            // kind the page does not offer.
            $site = Payload::enum($video, 'site', VideoSite::class);
            $kind = Payload::enum($video, 'type', VideoKind::class);

            $key = Payload::string($video, 'key');

            if ($site === null || $kind === null || $key === null) {
                continue;
            }

            $videos[] = new VideoData(
                key: $key,
                site: $site,
                type: $kind,
                name: Payload::text($video, 'name'),
                official: Payload::bool($video, 'official') ?? false,
                publishedAt: Payload::string($video, 'published_at'),
            );
        }

        return $videos;
    }

    /**
     * The original title when it differs from the displayed one (else null).
     *
     * @param  array<array-key, mixed>  $data
     */
    public function differingOriginal(array $data, string $originalKey, string $displayKey): ?string
    {
        $original = Payload::string($data, $originalKey);

        return $original !== null && $original !== Payload::string($data, $displayKey) ? $original : null;
    }

    /**
     * Distinct alternative titles from the alternative_titles payload, minus the
     * ones already shown (display / original), capped at 12.
     *
     * @param  array<array-key, mixed>  $data
     * @param  string  $key  'titles' (movie) or 'results' (show)
     * @return list<string>
     */
    public function alternativeTitles(array $data, string $key, string ...$exclude): array
    {
        $seen = [];

        foreach ($exclude as $title) {
            if ($title !== '') {
                $seen[mb_strtolower($title)] = true;
            }
        }

        $titles = [];

        foreach (Payload::list(data_get($data, "alternative_titles.{$key}")) as $entry) {
            $title = Payload::string($entry, 'title');

            if ($title === null || isset($seen[mb_strtolower($title)])) {
                continue;
            }

            $seen[mb_strtolower($title)] = true;
            $titles[] = $title;

            if (count($titles) === self::SHELF_SIZE) {
                break;
            }
        }

        return $titles;
    }

    /**
     * The show's networks with their logos, each linkable to its own network
     * page when TMDB gives an id.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<CreatorLink>
     */
    public function mapNetworks(array $data): array
    {
        $networks = [];

        foreach (Payload::list($data['networks'] ?? null) as $network) {
            $name = Payload::string($network, 'name');

            if ($name !== null) {
                $networks[] = new CreatorLink(
                    name: $name,
                    kind: CreatorKind::Network,
                    ref: Payload::id($network['id'] ?? null),
                    provider: 'tmdb',
                    logoPath: Payload::string($network, 'logo_path'),
                );
            }
        }

        return $networks;
    }

    /**
     * The title's production companies (studios) with their logos, each
     * linkable to its own company page — the film/TV counterpart of a show's
     * networks. Deduped by name; entries without an id render as plain text.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<CreatorLink>
     */
    public function mapStudios(array $data): array
    {
        $studios = [];
        $seen = [];

        foreach (Payload::list($data['production_companies'] ?? null) as $company) {
            $name = Payload::string($company, 'name');

            if ($name === null || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;

            $studios[] = new CreatorLink(
                name: $name,
                kind: CreatorKind::Company,
                ref: Payload::id($company['id'] ?? null),
                provider: 'tmdb',
                logoPath: Payload::string($company, 'logo_path'),
            );
        }

        return $studios;
    }

    /**
     * Similar titles (TMDB `similar`: genre/keyword based). Same known type as
     * the parent — the results carry no media_type — capped at 12.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<MediaSummary>
     */
    public function mapSimilar(array $data, string $type): array
    {
        return array_slice($this->payload->summaries(data_get($data, 'similar.results'), $type), 0, self::SHELF_SIZE);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<MediaSummary>
     */
    public function mapRelated(array $data): array
    {
        $related = [];

        $rawRelated = Payload::rows(data_get($data, 'recommendations.results'));

        foreach ($rawRelated as $entry) {
            if (count($related) === self::SHELF_SIZE) {
                break;
            }

            if (Payload::string($entry, 'poster_path') === null) {
                continue;
            }

            $summary = match ($entry['media_type'] ?? null) {
                'movie' => $this->payload->movieSummary($entry),
                'tv' => $this->payload->showSummary($entry),
                default => null,
            };

            if ($summary !== null) {
                $related[] = $summary;
            }
        }

        return $related;
    }

    /**
     * The title's logo for the hero — the top-voted English/neutral logo.
     * Null when none.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function bestLogo(array $data): ?string
    {
        foreach (Payload::list(data_get($data, 'images.logos')) as $logo) {
            $path = Payload::string($logo, 'file_path');

            if ($path !== null && $this->payload->inGallery($logo)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * `belongs_to_collection` of a movie response — no extra request.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function movieCollection(array $data): ?CollectionLink
    {
        $collection = Payload::map($data['belongs_to_collection'] ?? null);
        $ref = Payload::id($collection['id'] ?? null);
        $name = Payload::string($collection, 'name');

        if ($ref === null || $name === null) {
            return null;
        }

        return new CollectionLink(
            ref: $ref,
            name: $name,
            posterPath: Payload::string($collection, 'poster_path'),
            backdropPath: Payload::string($collection, 'backdrop_path'),
        );
    }

    /**
     * @return list<KeywordData>
     */
    public function mapKeywords(mixed $entries): array
    {
        $keywords = [];

        foreach (Payload::list($entries) as $entry) {
            $name = Payload::string($entry, 'name');
            $ref = Payload::id($entry['id'] ?? null);

            if ($name !== null && $ref !== null) {
                $keywords[] = new KeywordData($name, $ref);
            }
        }

        return $keywords;
    }

    /**
     * The same pluck as names(), for the objects TMDB keys by something else:
     * spoken languages by `english_name`, production countries by their code.
     *
     * @return list<string>
     */
    public function field(mixed $entries, string $key): array
    {
        $values = [];

        foreach (Payload::list($entries) as $entry) {
            $value = Payload::string($entry, $key);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Episodes a TV production also showed in cinemas — an anniversary special
     * or a feature-length finale. Rare, so the detail row stays hidden until
     * the list has something in it.
     *
     * @param  array<array-key, mixed>  $data  the tv details payload
     * @return list<array{seasonNumber: int, episodeNumber: int}>
     */
    public function screenedTheatrically(array $data): array
    {
        $rows = [];

        foreach (Payload::list(data_get($data, 'screened_theatrically.results')) as $entry) {
            $season = Payload::int($entry, 'season_number');
            $episode = Payload::int($entry, 'episode_number');

            if ($season !== null && $episode !== null) {
                $rows[] = ['seasonNumber' => $season, 'episodeNumber' => $episode];
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['seasonNumber'], $a['episodeNumber']] <=> [$b['seasonNumber'], $b['episodeNumber']]);

        return $rows;
    }

    /**
     * Whether an episode opens its season, told without the season's list. A
     * saga season numbered on from the one before (some anime) opens right
     * after the episodes of the earlier seasons, specials aside, and runs past
     * its own count, which a season numbered from one never does.
     *
     * @param  array<array-key, mixed>  $show  the tv details payload
     */
    public function opensSeason(array $show, int $season, int $number): bool
    {
        if ($number === 1) {
            return true;
        }

        $before = 0;
        $own = null;

        foreach (Payload::list($show['seasons'] ?? null) as $entry) {
            $at = Payload::int($entry, 'season_number');
            $count = Payload::int($entry, 'episode_count') ?? 0;

            if ($at === $season) {
                $own = $count;
            } elseif ($at !== null && $at > 0 && $at < $season) {
                $before += $count;
            }
        }

        return $own !== null && $number > $own && $number === $before + 1;
    }

    /**
     * One episode of a season payload. The season page and the show's leaves
     * both map through here, so a stored episode holds the same fields
     * whichever of the two fetched it.
     *
     * @param  array<array-key, mixed>  $episode  an entry of the season's `episodes`
     * @param  bool  $opensSeason  first in the season's list: some saga seasons keep absolute numbering, so the premiere is found by position
     */
    public function episodeSummary(array $episode, string $showRef, int $seasonNumber, bool $opensSeason, bool $closesEndedShow): MediaSummary
    {
        $number = Payload::positiveInt($episode, 'episode_number') ?? 0;
        // Kept as TMDB sends it, untrimmed: the stored title must not change
        // with the mapper.
        $title = $episode['name'] ?? null;
        $episodeType = Payload::enum($episode, 'episode_type', EpisodeType::class);

        return new MediaSummary(
            type: 'episode',
            ref: $showRef.':'.$seasonNumber.':'.$number,
            provider: $this->payload->code(),
            title: is_string($title) ? $title : '',
            year: $this->payload->yearOf($episode, 'air_date'),
            backdropPath: Payload::string($episode, 'still_path'),
            communityRating: $this->payload->rating($episode),
            releaseDate: Payload::string($episode, 'air_date'),
            number: $number,
            seasonNumber: $seasonNumber,
            runtime: Payload::positiveInt($episode, 'runtime'),
            overview: Payload::string($episode, 'overview'),
            episodeType: $episodeType,
            episodeBadge: EpisodeBadge::of($episodeType, $seasonNumber, $opensSeason, $closesEndedShow),
        );
    }
}

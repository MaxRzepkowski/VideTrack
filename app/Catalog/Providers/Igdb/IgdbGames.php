<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Data\CreatorLink;
use App\Catalog\Data\KeywordData;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\VideoData;
use App\Catalog\ItemNotFoundException;
use App\Catalog\TitleStatus;
use App\Catalog\VideoKind;
use App\Catalog\VideoSite;
use App\Support\Locales;
use App\Support\Payload;
use LogicException;

/**
 * A game's page: its details, time to beat, age ratings, the companies behind
 * it, videos, releases by platform and region, related games.
 */
final readonly class IgdbGames
{
    /** Games a similar shelf shows. */
    private const SHELF_SIZE = 12;

    /** More than any compilation IGDB lists ships in one bundle. */
    private const BUNDLE_CONTENTS_LIMIT = 50;

    /** More editions than any game IGDB lists has (The Witcher 3: three). */
    private const EDITIONS_LIMIT = 50;

    /** The game page's results in its one multiquery. */
    public const GAME = 'game';

    public const TIME_TO_BEAT = 'timeToBeat';

    public const EDITIONS = 'editions';

    public const BUNDLE_CONTENTS = 'bundleContents';

    /** Keywords kept beside the themes; the rest are noise. */
    private const KEYWORDS_KEPT = 10;

    /** IGDB game_statuses by name (queried from /v4/game_statuses). */
    private const STATUSES = [
        'Released' => TitleStatus::Released,
        'Alpha' => TitleStatus::Alpha,
        'Beta' => TitleStatus::Beta,
        'Early Access' => TitleStatus::EarlyAccess,
        'Offline' => TitleStatus::Offline,
        'Cancelled' => TitleStatus::Canceled,
        'Rumored' => TitleStatus::Rumored,
        'Delisted' => TitleStatus::Delisted,
    ];

    public function __construct(
        private IgdbClient $client,
        private IgdbPayload $payload,
        private IgdbReleases $releases,
        private IgdbPlayFacts $play,
        private IgdbStores $stores,
        private IgdbAgeRatings $ageRatings,
        private IgdbSeries $series,
        private IgdbFranchises $franchises,
        private IgdbPlatforms $platforms,
    ) {}

    public function details(string $type, string $ref, string $locale, ?string $region = null): MediaDetails
    {
        if ($type !== 'game') {
            throw new LogicException("IGDB does not serve details for type [{$type}].");
        }

        if (! ctype_digit($ref)) {
            throw new ItemNotFoundException("IGDB has no game [{$ref}].");
        }

        // One request for the page: the game, how long it takes, its editions
        // and what it bundles. The last two are asked of every game and set
        // aside where they do not apply: cheaper than a second request once
        // the game's own answer has said which it is.
        $answer = $this->client->multiquery([
            self::GAME => ['games', 'fields name, summary, storyline, first_release_date, total_rating, total_rating_count, hypes, game_type, game_status.status, url, '
            .'cover.image_id, screenshots.image_id, artworks.image_id, '
            .'genres.name, themes.name, keywords.name, game_modes.name, platforms.name, '
            .'game_engines.name, '.IgdbFranchises::FIELDS.', '
            .'involved_companies.company.id, involved_companies.company.name, involved_companies.developer, involved_companies.publisher, involved_companies.porting, involved_companies.supporting, '
            .'videos.video_id, videos.name, websites.url, websites.type, '
            .IgdbReleases::FIELDS.', '
            .IgdbPlayFacts::FIELDS.', '
            .IgdbStores::FIELDS.', '
            .IgdbPayload::ALTERNATIVE_NAME_FIELDS.', '
            .IgdbSeries::FIELDS.', '
            .IgdbAgeRatings::FIELDS.', '
            .'parent_game.id, parent_game.name, version_parent.id, version_parent.name, '
            .'dlcs.name, dlcs.first_release_date, dlcs.cover.image_id, dlcs.total_rating, dlcs.game_type, '
            .'expansions.name, expansions.first_release_date, expansions.cover.image_id, expansions.total_rating, expansions.game_type, '
            .'standalone_expansions.name, standalone_expansions.first_release_date, standalone_expansions.cover.image_id, standalone_expansions.total_rating, standalone_expansions.game_type, '
            .'bundles.name, bundles.first_release_date, bundles.cover.image_id, bundles.total_rating, bundles.game_type, '
            .'remakes.name, remakes.first_release_date, remakes.cover.image_id, remakes.total_rating, remakes.game_type, '
            .'remasters.name, remasters.first_release_date, remasters.cover.image_id, remasters.total_rating, remasters.game_type, '
            .IgdbReleases::relatedFields().', '
            .'ports.name, ports.first_release_date, ports.cover.image_id, ports.total_rating, ports.game_type, '
            .'similar_games.name, similar_games.first_release_date, similar_games.cover.image_id, similar_games.total_rating, similar_games.game_type; '
            ."where id = {$ref};"],
            self::TIME_TO_BEAT => ['game_time_to_beats', "fields hastily, normally, completely; where game_id = {$ref}; limit 1;"],
            self::EDITIONS => ['games', 'fields '.IgdbPayload::SUMMARY_FIELDS."; where version_parent = {$ref}; sort first_release_date asc; limit ".self::EDITIONS_LIMIT.';'],
            self::BUNDLE_CONTENTS => ['games', 'fields '.IgdbPayload::SUMMARY_FIELDS."; where bundles = ({$ref}); limit ".self::BUNDLE_CONTENTS_LIMIT.';'],
        ], $this->payload->ttl('details'), required: self::GAME);

        $data = $answer[self::GAME][0] ?? throw new ItemNotFoundException("IGDB returned no game for [{$ref}].");

        $screenshots = $this->payload->imageUrls($data, 'screenshots', '1080p');
        $artworks = $this->payload->imageUrls($data, 'artworks', '1080p');

        $timeToBeat = $this->timeToBeat($answer[self::TIME_TO_BEAT][0] ?? []);
        $bundleContents = $this->bundleContents($data, $answer[self::BUNDLE_CONTENTS]);
        $versionParent = Payload::map($data['version_parent'] ?? null);
        $editions = $this->editions($data, $answer[self::EDITIONS], $ref);
        $parent = Payload::map($data['parent_game'] ?? null) ?: $versionParent ?: $this->bundleMainGame($bundleContents);
        $ageRatings = $this->ageRatings->of($data);

        return new MediaDetails(
            summary: $this->payload->summary(
                $data,
                backdropPath: $artworks[0] ?? $screenshots[0] ?? null,
                releasePrecision: $this->releases->firstDatePrecision($data),
            ),
            overview: Payload::string($data, 'summary') ?? Payload::string($data, 'storyline'),
            // The average completion time doubles as the game's "runtime" so
            // play-time statistics work like they do for movies.
            runtime: $timeToBeat['normally'] ?? $timeToBeat['hastily'] ?? $timeToBeat['completely'] ?? null,
            genres: Payload::names($data['genres'] ?? null),
            status: $this->status($data),
            certification: AgeRatingBoard::chip(IgdbAgeRatings::byBoard($ageRatings), $region ?? Locales::region($locale)),
            keywords: array_map(
                fn (string $name): KeywordData => new KeywordData($name),
                [
                    ...Payload::names($data['themes'] ?? null),
                    ...array_slice(Payload::names($data['keywords'] ?? null), 0, self::KEYWORDS_KEPT),
                ],
            ),
            platformReleases: $this->releases->byPlatform($data),
            // A store's page is in `stores`, with the rest of where to buy it.
            externalLinks: array_diff_key($this->payload->externalLinks($data), array_flip(IgdbStores::websiteKeys())),
            extra: [
                'developerLinks' => $this->companyLinks($data, 'developer'),
                'publisherLinks' => $this->companyLinks($data, 'publisher'),
                'portingLinks' => $this->companyLinks($data, 'porting') ?: null,
                'supportingLinks' => $this->companyLinks($data, 'supporting') ?: null,
                // Alone, the storyline stands as the overview; beside a summary it is its own text.
                'storyline' => Payload::string($data, 'summary') === null ? null : Payload::string($data, 'storyline'),
                'platforms' => Payload::names($data['platforms'] ?? null),
                'gameModes' => Payload::names($data['game_modes'] ?? null),
                'alternativeTitles' => $this->payload->alternativeTitles($data) ?: null,
                'perspectives' => $this->play->perspectives($data) ?: null,
                'languages' => $this->play->languages($data) ?: null,
                'multiplayer' => $this->play->multiplayer($data) ?: null,
                'criticsRating' => $this->play->critics($data),
                'playersRating' => $this->play->players($data),
                'stores' => $this->stores->of($data) ?: null,
                'ageRatings' => $ageRatings ?: null,
                'engines' => Payload::names($data['game_engines'] ?? null),
                'franchises' => Payload::names($data['franchises'] ?? null),
                'franchiseLinks' => $this->franchises->of($data) ?: null,
                'platformLinks' => $this->platforms->of($data) ?: null,
                'timeToBeat' => array_filter($timeToBeat) ?: null,
                'dlcs' => $this->mapRelatedGames($data, ['dlcs', 'expansions']) ?: null,
                'otherReleases' => $this->mapRelatedGames($data, ['bundles', 'remakes', 'remasters', 'ports', 'standalone_expansions']) ?: null,
                // The game this one is a DLC, an edition or a bundle of: its page
                // links up to it as a season links up to its show.
                'parentGameRef' => Payload::id($parent['id'] ?? null),
                'parentGameTitle' => Payload::string($parent, 'name'),
                'editions' => array_map(fn (array $game): MediaSummary => $this->payload->summary($game), $editions) ?: null,
                'bundleContents' => array_map(fn (array $game): MediaSummary => $this->payload->summary($game), $bundleContents) ?: null,
            ],
            videos: $this->mapVideos($data),
            backdrops: array_values(array_unique([...$screenshots, ...$artworks])),
            posters: array_filter([$this->payload->imageUrl($data, 'cover', 'cover_big_2x')]),
            related: $this->mapRelated($data),
            collection: $this->series->of($data),
            relatedReleases: $this->releases->related($data),
        );
    }

    /**
     * The status IGDB names, if one we know: a released game mostly has none.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function status(array $data): ?TitleStatus
    {
        $name = Payload::string(Payload::map($data['game_status'] ?? null), 'status');

        return $name === null ? null : self::STATUSES[$name] ?? null;
    }

    /**
     * What a bundle holds; any other game holds nothing, whatever lists it.
     * IGDB records a bundle from the inside: every game in it lists the
     * bundle among its `bundles`. A row without an id links nowhere.
     *
     * @param  array<array-key, mixed>  $game
     * @param  list<array<array-key, mixed>>  $listing
     * @return list<array<array-key, mixed>>
     */
    private function bundleContents(array $game, array $listing): array
    {
        return Payload::intEnum($game, 'game_type', GameType::class) === GameType::Bundle ? Payload::rows($listing) : [];
    }

    /**
     * A game's editions (Game of the Year, Deluxe, Complete). An edition
     * shows its game's other editions on that game's page, not its own, so
     * an edition lists none. IGDB records them from the edition's side: each
     * names its game as `version_parent`. A game is never an edition of
     * itself, whatever an answer says, and a row without an id links nowhere.
     *
     * @param  array<array-key, mixed>  $game
     * @param  list<array<array-key, mixed>>  $listing
     * @return list<array<array-key, mixed>>
     */
    private function editions(array $game, array $listing, string $ref): array
    {
        if (Payload::map($game['version_parent'] ?? null) !== []) {
            return [];
        }

        return array_values(array_filter(Payload::rows($listing), fn (array $edition): bool => Payload::id($edition['id'] ?? null) !== $ref));
    }

    /**
     * The game a bundle is built around: its one main game (Cyberpunk 2077 in
     * the game-and-expansion bundle). A compilation of several has none.
     *
     * @param  list<array<array-key, mixed>>  $contents
     * @return array<array-key, mixed>
     */
    private function bundleMainGame(array $contents): array
    {
        $main = array_values(array_filter($contents, fn (array $game): bool => Payload::intEnum($game, 'game_type', GameType::class) === GameType::Main));

        return count($main) === 1 ? $main[0] : [];
    }

    /**
     * IGDB completion times in minutes: rushed / average / completionist.
     * Missing for most niche titles — every key may be null.
     *
     * @param  array<array-key, mixed>  $data  the game's `game_time_to_beats` row
     * @return array{hastily: int|null, normally: int|null, completely: int|null}
     */
    private function timeToBeat(array $data): array
    {
        $minutes = function (string $key) use ($data): ?int {
            $seconds = Payload::int($data, $key) ?? 0;

            return $seconds > 0 ? (int) round($seconds / 60) : null;
        };

        return [
            'hastily' => $minutes('hastily'),
            'normally' => $minutes('normally'),
            'completely' => $minutes('completely'),
        ];
    }

    /**
     * Companies of one role as linkable creators — the game-page block that
     * reaches the company page. Deduped by name; a company missing an id still
     * renders (as plain text) rather than vanishing.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<CreatorLink>
     */
    private function companyLinks(array $data, string $role): array
    {
        $links = [];
        $seen = [];

        $companies = Payload::list($data['involved_companies'] ?? null);

        foreach ($companies as $company) {
            $name = data_get($company, 'company.name');

            if (($company[$role] ?? false) !== true || ! is_string($name) || $name === '' || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;

            $links[] = new CreatorLink(
                name: $name,
                kind: CreatorKind::Company,
                ref: Payload::id(data_get($company, 'company.id')),
                provider: 'igdb',
            );
        }

        return $links;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<VideoData>
     */
    private function mapVideos(array $data): array
    {
        $videos = [];

        $rawVideos = Payload::list($data['videos'] ?? null);

        foreach ($rawVideos as $video) {
            $key = Payload::string($video, 'video_id');

            if ($key === null) {
                continue;
            }

            $name = Payload::string($video, 'name') ?? 'Trailer';

            $videos[] = new VideoData(
                key: $key,
                site: VideoSite::YouTube,
                // IGDB has no video taxonomy beyond the name.
                type: stripos($name, 'trailer') === false ? VideoKind::Clip : VideoKind::Trailer,
                name: $name,
                official: false,
            );
        }

        return $videos;
    }

    /**
     * Merges several of a game's related-game relations (e.g. dlcs+expansions,
     * or bundles+remakes+ports) into one summary list — covered only, deduped
     * by id, newest first.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     * @return list<MediaSummary>
     */
    private function mapRelatedGames(array $data, array $keys): array
    {
        $items = [];
        $seen = [];

        foreach ($keys as $key) {
            foreach (Payload::rows($data[$key] ?? null) as $game) {
                $id = $this->payload->ref($game);

                if (empty($game['cover']) || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $items[] = $this->payload->summary($game);
            }
        }

        usort($items, fn (MediaSummary $a, MediaSummary $b) => ($b->releaseDate ?? '') <=> ($a->releaseDate ?? ''));

        return $items;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<MediaSummary>
     */
    private function mapRelated(array $data): array
    {
        $related = [];

        $rawRelated = Payload::rows($data['similar_games'] ?? null);

        foreach ($rawRelated as $game) {
            if (count($related) === self::SHELF_SIZE) {
                break;
            }

            if (! empty($game['cover'])) {
                $related[] = $this->payload->summary($game);
            }
        }

        return $related;
    }
}

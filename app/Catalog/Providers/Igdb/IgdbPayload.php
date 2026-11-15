<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\Paginated;
use App\Catalog\DatePrecision;
use App\Catalog\Matching;
use App\Catalog\ProviderException;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Reading IGDB's payload shapes: a game's summary, its images, rating and
 * dates, its external links, the request syntax and cache lifetimes — what
 * every part of the provider shares.
 */
final readonly class IgdbPayload
{
    private const IMAGE_BASE = 'https://images.igdb.com/igdb/image/upload';

    /** IGDB collection_membership_types (queried from /v4/collection_membership_types): a game of a series' line, not a spin-off of it. */
    public const MEMBER = 1;

    /** Games per page of the by-genre and filtered listings. */
    public const LISTING_PAGE_SIZE = 20;

    public const SUMMARY_FIELDS = 'name, first_release_date, cover.image_id, total_rating, game_type, hypes';

    /** The other names of a game, where a caller places it by name. */
    public const ALTERNATIVE_NAME_FIELDS = 'alternative_names.name, alternative_names.comment';

    /**
     * IGDB's alternative-name comments that name no title a person would
     * write: the executable, the logo's capitals. The comment is free text,
     * so it is matched loosely.
     */
    private const NOT_A_TITLE = '/executable|stylized/i';

    /**
     * IGDB website_types (queried from /v4/website_types) → our link keys.
     * The frontend renders one dedicated icon per key.
     */
    private const WEBSITE_TYPES = [
        1 => 'homepage',
        2 => 'fandom',
        3 => 'wikipedia',
        4 => 'facebook',
        5 => 'x',
        6 => 'twitch',
        8 => 'instagram',
        9 => 'youtube',
        10 => 'appstore',
        11 => 'appstore',
        12 => 'googleplay',
        13 => 'steam',
        14 => 'reddit',
        15 => 'itch',
        16 => 'epicgames',
        17 => 'gog',
        18 => 'discord',
        19 => 'bluesky',
        22 => 'xbox',
        23 => 'playstation',
        24 => 'nintendo',
        25 => 'meta',
        26 => 'gamejolt',
    ];

    public function code(): string
    {
        return 'igdb';
    }

    /**
     * One page of a game listing, numbered against the full count of its
     * `where` clause.
     *
     * @param  list<array<array-key, mixed>>  $games
     * @return Paginated<MediaSummary>
     */
    public function paginated(array $games, int $page, int $total): Paginated
    {
        return new Paginated(
            items: array_map(fn (array $game): MediaSummary => $this->summary($game), Payload::rows($games)),
            page: $page,
            totalPages: max(1, (int) ceil($total / self::LISTING_PAGE_SIZE)),
            total: $total,
        );
    }

    /**
     * The id of a game or a company as a ref. IGDB never serves a row without
     * one, so an answer that does is not one we can read.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function ref(array $data): string
    {
        return Payload::id($data['id'] ?? null) ?? throw ProviderException::missingId('IGDB');
    }

    /** The summary fields of games reached through a relation (`games`, `game`), for a query asking about something else. */
    public static function summaryFieldsOf(string $relation, string $extra = ''): string
    {
        $fields = $extra === '' ? self::SUMMARY_FIELDS : self::SUMMARY_FIELDS.', '.$extra;

        return implode(', ', array_map(fn (string $field): string => $relation.'.'.trim($field), explode(',', $fields)));
    }

    /**
     * Released first by date, the announced (undated) after them.
     *
     * @param  list<MediaSummary>  $games
     * @return list<MediaSummary>
     */
    public static function inReleaseOrder(array $games): array
    {
        usort($games, fn (MediaSummary $a, MediaSummary $b): int => [$a->releaseDate === null, $a->releaseDate] <=> [$b->releaseDate === null, $b->releaseDate]);

        return $games;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function summary(array $data, ?string $backdropPath = null, DatePrecision $releasePrecision = DatePrecision::Day): MediaSummary
    {
        $released = Payload::int($data, 'first_release_date');
        $releaseDate = $released === null ? null : $this->utcDate($released);

        // A non-main game_type rides along so cards can badge it (DLC, Bundle…).
        $kind = Payload::intEnum($data, 'game_type', GameType::class)?->kind();

        return new MediaSummary(
            type: 'game',
            ref: $this->ref($data),
            provider: $this->code(),
            title: Payload::text($data, 'name'),
            year: Payload::yearOf($releaseDate),
            posterPath: $this->imageUrl($data, 'cover', 'cover_big_2x'),
            backdropPath: $backdropPath,
            communityRating: $this->rating($data),
            releaseDate: $releaseDate,
            extra: [
                ...($kind === null ? [] : ['gameType' => $kind->value]),
                // How many follow it ahead of release; the card says it while the game is still to come.
                ...(($hypes = Payload::int($data, 'hypes') ?? 0) > 0 ? ['awaitedBy' => $hypes] : []),
            ],
            releasePrecision: $releaseDate === null ? DatePrecision::Day : $releasePrecision,
            alternativeTitles: $this->alternativeTitles($data),
        );
    }

    /**
     * The game's other names worth matching or showing, its own name and
     * the executables left out.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public function alternativeTitles(array $data): array
    {
        $own = Payload::string($data, 'name');
        $titles = [];

        foreach (Payload::list($data['alternative_names'] ?? null) as $alternative) {
            $name = Payload::string($alternative, 'name');
            $comment = Payload::string($alternative, 'comment');

            if ($name === null || ($comment !== null && preg_match(self::NOT_A_TITLE, $comment) === 1) || str_ends_with(mb_strtolower($name), '.exe')) {
                continue;
            }

            if (($own === null || ! Matching::sameTitle($name, $own)) && ! in_array($name, $titles, true)) {
                $titles[] = $name;
            }
        }

        return $titles;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     */
    public function externalLinks(array $data): array
    {
        $links = [];

        $websites = Payload::list($data['websites'] ?? null);

        foreach ($websites as $website) {
            $label = self::WEBSITE_TYPES[Payload::int($website, 'type') ?? -1] ?? null;
            $url = Payload::string($website, 'url');

            if ($label !== null && $url !== null && ! isset($links[$label])) {
                $links[$label] = $url;
            }
        }

        $igdbUrl = Payload::string($data, 'url');

        if ($igdbUrl !== null) {
            $links['igdb'] = $igdbUrl;
        }

        return $links;
    }

    /**
     * Absolute CDN URL of a single named image reference.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function imageUrl(array $data, string $key, string $size, string $format = 'jpg'): ?string
    {
        $imageId = data_get($data, "{$key}.image_id");

        return is_string($imageId) && $imageId !== ''
            ? self::IMAGE_BASE."/t_{$size}/{$imageId}.{$format}"
            : null;
    }

    /**
     * Absolute CDN URLs of an image collection.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public function imageUrls(array $data, string $key, string $size): array
    {
        $urls = [];

        foreach (array_slice(Payload::list($data[$key] ?? null), 0, 30) as $image) {
            $imageId = Payload::string($image, 'image_id');

            if ($imageId !== null) {
                $urls[] = self::IMAGE_BASE."/t_{$size}/{$imageId}.jpg";
            }
        }

        return $urls;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function rating(array $data): ?float
    {
        // IGDB ratings are 0–100; the app uses the 0–10 scale everywhere.
        $rating = Payload::float($data, 'total_rating') ?? 0.0;

        return $rating > 0 ? round($rating / 10, 1) : null;
    }

    public function ttl(string $kind): int
    {
        return config()->integer("tracker.igdb.cache.{$kind}");
    }

    public function assertGame(string $type): void
    {
        if ($type !== 'game') {
            throw new LogicException("IGDB does not serve discover listings for type [{$type}].");
        }
    }

    /**
     * A search term inside an Apicalypse string literal. Quotes and
     * backslashes are dropped, not escaped — a deliberate loss: they could
     * break out of the literal, and a title search hardly ever needs them.
     */
    public function escape(string $value): string
    {
        return str_replace(['\\', '"'], '', $value);
    }

    /**
     * The numeric ids out of a filter value. Everything reaching Apicalypse is
     * interpolated into a query string, so nothing but digits may pass.
     *
     * @return list<string>
     */
    public function numericIds(mixed $value): array
    {
        $values = is_string($value) ? [$value] : $value;

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            $values,
            fn (mixed $item): bool => is_string($item) && ctype_digit($item),
        ));
    }

    /**
     * The cutoff for "upcoming", inclusive: the start of today in UTC (a game
     * dated today is still upcoming, as on TMDB) rather than the
     * current second, so the query (and the cache key derived from it) stays
     * the same for a whole day instead of missing on every request.
     */
    public function startOfTodayUtc(): int
    {
        return CarbonImmutable::now('UTC')->startOfDay()->getTimestamp();
    }

    /**
     * IGDB epochs are UTC midnights; read them in UTC so the app timezone
     * never moves a release to the day before, as year filters already do.
     */
    public function utcDate(int $epoch): string
    {
        return CarbonImmutable::createFromTimestampUTC($epoch)->toDateString();
    }
}

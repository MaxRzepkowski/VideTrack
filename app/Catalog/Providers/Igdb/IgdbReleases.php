<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Data\PlatformRelease;
use App\Catalog\Data\RelatedRelease;
use App\Catalog\DatePrecision;
use App\Catalog\GameKind;
use App\Catalog\ReleaseRegion;
use App\Support\Payload;

/**
 * A game's dated releases: one per platform, kind and region, each saying how
 * much of its date IGDB knows ("2028" arrives stamped with the year's last
 * day), and how much of the game's first date is known.
 */
final readonly class IgdbReleases
{
    /** The `release_dates` fields both readings need, for the details query. */
    public const FIELDS = 'release_dates.date, release_dates.date_format.format, release_dates.platform.abbreviation, '
        .'release_dates.platform.name, release_dates.status.name, release_dates.release_region';

    /**
     * The relations naming a game's releases of its own a calendar shows
     * beside it, and what each is: the relation says it, whatever the
     * related game's own type was filed as.
     */
    private const RELATED = [
        'dlcs' => GameKind::Dlc,
        'expansions' => GameKind::Expansion,
        'standalone_expansions' => GameKind::StandaloneExpansion,
        'remakes' => GameKind::Remake,
        'remasters' => GameKind::Remaster,
    ];

    /** More than a game with years of DLC needs on a calendar (The Sims 4 lists over a hundred, most long out). */
    private const RELATED_LIMIT = 60;

    /**
     * What IGDB calls a plain release. It leaves the status off plenty of
     * them as well (TCG Card Shop Simulator ships to Switch with none on the
     * day it ships to PC as "Full Release"): both are the same release, so
     * neither carries a status — one marks only a release of another kind.
     */
    private const PLAIN_RELEASE = 'Full Release';

    /** IGDB release_region codes mapped to the catalog's release regions. */
    private const RELEASE_REGIONS = [
        1 => ReleaseRegion::Europe,
        2 => ReleaseRegion::NorthAmerica,
        3 => ReleaseRegion::Australia,
        4 => ReleaseRegion::NewZealand,
        5 => ReleaseRegion::Japan,
        6 => ReleaseRegion::China,
        7 => ReleaseRegion::Asia,
        8 => ReleaseRegion::Worldwide,
        9 => ReleaseRegion::Korea,
        10 => ReleaseRegion::Brazil,
    ];

    /**
     * IGDB date_formats by name (queried from /v4/date_formats). "TBD" is not
     * here: such a release carries no date at all.
     */
    private const PRECISIONS = [
        'YYYYMMDD' => DatePrecision::Day,
        'YYYYMM' => DatePrecision::Month,
        'YYYYQ1' => DatePrecision::Quarter,
        'YYYYQ2' => DatePrecision::Quarter,
        'YYYYQ3' => DatePrecision::Quarter,
        'YYYYQ4' => DatePrecision::Quarter,
        'YYYY' => DatePrecision::Year,
    ];

    public function __construct(private IgdbPayload $payload) {}

    /**
     * The fields {@see related()} reads beyond the shelves' own, for the
     * details query: each related game's releases, as the game's are read.
     */
    public static function relatedFields(): string
    {
        $fields = [];

        foreach (array_keys(self::RELATED) as $relation) {
            foreach (explode(',', self::FIELDS) as $field) {
                $fields[] = $relation.'.'.trim($field);
            }
        }

        return implode(', ', $fields);
    }

    /**
     * The game's DLC, expansions, remakes and remasters that have a date,
     * newest first, each with its own releases per platform. One listed under
     * two relations is the first relation's.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<RelatedRelease>
     */
    public function related(array $data): array
    {
        $related = [];

        foreach (self::RELATED as $relation => $kind) {
            foreach (Payload::rows($data[$relation] ?? null) as $game) {
                $ref = $this->payload->ref($game);
                $first = Payload::int($game, 'first_release_date');
                $title = Payload::string($game, 'name');

                if ($first === null || $title === null || isset($related[$ref])) {
                    continue;
                }

                $related[$ref] = new RelatedRelease(
                    ref: $ref,
                    title: $title,
                    kind: $kind,
                    posterPath: $this->payload->imageUrl($game, 'cover', 'cover_big_2x'),
                    date: $this->payload->utcDate($first),
                    precision: $this->firstDatePrecision($game),
                    platformReleases: $this->byPlatform($game),
                );
            }
        }

        $related = array_values($related);
        usort($related, fn (RelatedRelease $a, RelatedRelease $b): int => [$b->date, $a->ref] <=> [$a->date, $b->ref]);

        return array_slice($related, 0, self::RELATED_LIMIT);
    }

    /**
     * Per-platform release dates — ports, early access, next-gen patches and
     * so on (release_dates.status stays unfiltered on purpose: every kind of
     * premiere is calendar-worthy). Every regional variant is persisted; the
     * calendar picks one per the viewing user's region.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<PlatformRelease>
     */
    public function byPlatform(array $data): array
    {
        $byKey = [];

        foreach (Payload::list($data['release_dates'] ?? null) as $release) {
            $date = Payload::int($release, 'date');

            if ($date === null) {
                continue;
            }

            $platform = data_get($release, 'platform.abbreviation') ?? data_get($release, 'platform.name');
            $platform = is_string($platform) && $platform !== '' ? $platform : null;
            $status = data_get($release, 'status.name');
            $status = is_string($status) && $status !== '' && $status !== self::PLAIN_RELEASE ? $status : null;
            $region = self::RELEASE_REGIONS[Payload::int($release, 'release_region') ?? 0] ?? null;

            $entry = new PlatformRelease($this->payload->utcDate($date), $platform, $status, $region, $this->precision($release));

            // Exact duplicates (same platform, kind and region) keep the
            // earliest date; on the same day, the better-known one.
            $key = "{$platform}|{$status}|{$region?->value}";
            $current = $byKey[$key] ?? null;

            if ($current === null || $entry->date < $current->date || ($entry->date === $current->date && $entry->precision->isFinerThan($current->precision))) {
                $byKey[$key] = $entry;
            }
        }

        $releases = array_values($byKey);

        usort($releases, fn (PlatformRelease $a, PlatformRelease $b) => $a->date <=> $b->date);

        return $releases;
    }

    /**
     * How much of `first_release_date` is known: IGDB copies it from the
     * earliest release, so the releases dated that very moment say it — the
     * best-known of them, as one exact release makes the day real. A first
     * date no release carries (IGDB had not recomputed it) reads as exact.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function firstDatePrecision(array $data): DatePrecision
    {
        $first = Payload::int($data, 'first_release_date');
        $best = null;

        foreach (Payload::list($data['release_dates'] ?? null) as $release) {
            if ($first === null || Payload::int($release, 'date') !== $first) {
                continue;
            }

            $precision = $this->precision($release);

            if ($best === null || $precision->isFinerThan($best)) {
                $best = $precision;
            }
        }

        return $best ?? DatePrecision::Day;
    }

    /**
     * A format IGDB has not used before reads as an exact day.
     *
     * @param  array<array-key, mixed>  $release
     */
    private function precision(array $release): DatePrecision
    {
        $format = Payload::string(Payload::map($release['date_format'] ?? null), 'format');

        return $format === null ? DatePrecision::Day : self::PRECISIONS[$format] ?? DatePrecision::Day;
    }
}

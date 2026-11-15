<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Data\CertificationData;
use App\Catalog\Data\ReleaseEntry;
use App\Catalog\FilmReleaseType;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Support\Payload;

/**
 * Release dates and age certifications by region: the chip's certification for
 * the viewer's region, every region's ratings, and when a film left cinemas.
 *
 * @phpstan-type ChosenCertification array{
 *     code: string|null,
 *     region: string|null,
 *     meaning: string|null,
 *     certifications: list<CertificationData>,
 *     descriptors: list<string>
 * }
 */
final readonly class TmdbReleases
{
    /** TMDB release type: a film's VOD / streaming debut. */
    private const RELEASE_TYPE_DIGITAL = 4;

    /** TMDB release type: a film's disc (Blu-ray / DVD) release. */
    private const RELEASE_TYPE_PHYSICAL = 5;

    /** TMDB's release type codes, as the kinds a release entry carries. */
    private const RELEASE_KINDS = [
        1 => FilmReleaseType::Premiere,
        2 => FilmReleaseType::Limited,
        3 => FilmReleaseType::Theatrical,
        self::RELEASE_TYPE_DIGITAL => FilmReleaseType::Digital,
        self::RELEASE_TYPE_PHYSICAL => FilmReleaseType::Disc,
        6 => FilmReleaseType::Tv,
    ];

    public function __construct(
        private TmdbClient $client,
        private TmdbPayload $payload,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @return ChosenCertification
     */
    public function movieCertification(array $data, string $viewerRegion): array
    {
        /** @var list<array{region: string, code: string}> $entries */
        $entries = [];
        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<string, list<string>> $descriptorsByRegion */
        $descriptorsByRegion = [];

        foreach (Payload::list(data_get($data, 'release_dates.results')) as $release) {
            $region = Payload::string($release, 'iso_3166_1');

            if ($region === null) {
                continue;
            }

            foreach (Payload::list($release['release_dates'] ?? null) as $date) {
                foreach (Payload::strings($date['descriptors'] ?? null) as $descriptor) {
                    if ($descriptor !== '' && ! in_array($descriptor, $descriptorsByRegion[$region] ?? [], true)) {
                        $descriptorsByRegion[$region][] = $descriptor;
                    }
                }

                $code = Payload::string($date, 'certification');

                if ($code === null || isset($seen["{$region}:{$code}"])) {
                    continue;
                }

                $seen["{$region}:{$code}"] = true;
                $entries[] = ['region' => $region, 'code' => $code];
            }
        }

        return $this->certificationData('movie', $entries, $descriptorsByRegion, $viewerRegion);
    }

    /**
     * Every dated release of a film, flattened out of TMDB's per-region
     * nesting: one row per country per kind. Feeds both the summary (earliest
     * of each kind) and the per-country breakdown behind "show more".
     *
     * The whole world is kept deliberately. This corner of TMDB is
     * crowdsourced and thin per country — Toy Story 5 carries a digital date
     * in eight regions and a disc date in two — so reading a single region
     * reports "not out" for films watchable for weeks.
     *
     * `kind` keeps all six TMDB types apart. The section still groups the
     * three cinema kinds under one tile — a festival premiere, a limited run
     * and wide distribution are one moment to someone deciding what to watch —
     * but the per-country dialog names the exact one, so a film that played
     * two festivals a year before release stops reading as released.
     *
     * `note` is TMDB's free-text field ("Netflix", "IMAX re-release"), passed
     * through unparsed: volunteers fill it, so it means whatever they wrote.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<ReleaseEntry>
     */
    public function releaseBreakdown(array $data): array
    {
        $kinds = self::RELEASE_KINDS;

        $rows = [];

        foreach (Payload::list(data_get($data, 'release_dates.results')) as $release) {
            $region = Payload::string($release, 'iso_3166_1');

            if ($region === null) {
                continue;
            }

            foreach (Payload::list($release['release_dates'] ?? null) as $entry) {
                $kind = $kinds[Payload::int($entry, 'type') ?? 0] ?? null;

                if ($kind === null) {
                    continue;
                }

                // TMDB dates are full ISO 8601 ("2026-08-18T00:00:00.000Z");
                // only the day matters here. Undated entries (a certification
                // filed without a date) carry an empty string — skip them.
                $date = substr(Payload::text($entry, 'release_date'), 0, 10);

                if ($date === '') {
                    continue;
                }

                $rows[] = new ReleaseEntry(
                    region: $region,
                    kind: $kind,
                    date: $date,
                    note: Payload::string($entry, 'note'),
                );
            }
        }

        usort($rows, fn (ReleaseEntry $a, ReleaseEntry $b) => [$a->date, $a->region] <=> [$b->date, $b->region]);

        return $rows;
    }

    /**
     * The note of the release that took a film out of cinemas — the earliest
     * digital or disc row worldwide, the one the calendar announces — so its
     * note ("Netflix") is the one worth carrying. Null when that row has none.
     *
     * @param  list<ReleaseEntry>  $rows
     */
    public function homeReleaseNote(array $rows): ?string
    {
        $earliest = null;

        foreach ($rows as $row) {
            if (in_array($row->kind, [FilmReleaseType::Digital, FilmReleaseType::Disc], true) && ($earliest === null || $row->date < $earliest->date)) {
                $earliest = $row;
            }
        }

        return $earliest?->note;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return ChosenCertification
     */
    public function showCertification(array $data, string $viewerRegion): array
    {
        /** @var list<array{region: string, code: string}> $entries */
        $entries = [];
        /** @var array<string, true> $seen */
        $seen = [];

        foreach (Payload::list(data_get($data, 'content_ratings.results')) as $rating) {
            $region = Payload::string($rating, 'iso_3166_1');
            $code = Payload::string($rating, 'rating');

            if ($region === null || $code === null) {
                continue;
            }

            if (isset($seen["{$region}:{$code}"])) {
                continue;
            }

            $seen["{$region}:{$code}"] = true;
            $entries[] = ['region' => $region, 'code' => $code];
        }

        return $this->certificationData('tv', $entries, [], $viewerRegion);
    }

    /**
     * Enrich the title's raw ratings with TMDB's long-lived dictionary while
     * keeping the title page usable when that secondary request fails.
     *
     * @param  list<array{region: string, code: string}>  $entries
     * @param  array<string, list<string>>  $descriptorsByRegion
     * @return ChosenCertification
     */
    private function certificationData(string $type, array $entries, array $descriptorsByRegion, string $viewerRegion): array
    {
        $dictionary = $entries === [] ? [] : $this->certificationDictionary($type);
        $certifications = array_map(fn (array $entry): CertificationData => new CertificationData(
            region: $entry['region'],
            code: $entry['code'],
            meaning: $dictionary[$entry['region']][$entry['code']] ?? null,
        ), $entries);

        $selected = null;

        // The viewer's own country first, then the US, which TMDB rates
        // nearly everything for.
        foreach (array_unique([$viewerRegion, 'US']) as $wanted) {
            foreach ($certifications as $certification) {
                if ($certification->region === $wanted) {
                    $selected = $certification;
                    break 2;
                }
            }
        }

        return [
            'code' => $selected?->code,
            'region' => $selected?->region,
            'meaning' => $selected?->meaning,
            'certifications' => $certifications,
            'descriptors' => $selected === null ? [] : ($descriptorsByRegion[$selected->region] ?? []),
        ];
    }

    /**
     * TMDB certification dictionary, ordered from the mildest rating to the
     * strictest within every region.
     *
     * @return array<string, array<string, string>>
     */
    public function certificationDictionary(string $type): array
    {
        try {
            $data = $this->client->get(
                "certification/{$type}/list",
                [],
                $this->payload->ttl('dictionary'),
                'certifications',
            );
        } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
            return [];
        }

        $dictionary = [];

        foreach (Payload::map($data['certifications'] ?? null) as $region => $entries) {
            if (! is_string($region)) {
                continue;
            }

            $entries = Payload::list($entries);
            usort($entries, fn (array $a, array $b): int => (Payload::int($a, 'order') ?? 0) <=> (Payload::int($b, 'order') ?? 0));

            foreach ($entries as $entry) {
                $code = Payload::string($entry, 'certification');

                if ($code !== null) {
                    $dictionary[$region][$code] = Payload::text($entry, 'meaning');
                }
            }
        }

        return $dictionary;
    }
}

<?php

namespace App\Integrations\TvTime;

use App\Integrations\Import\CsvStream;
use App\Integrations\Import\ZipExport;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Generator;

/**
 * Read-only view over a TV Time GDPR data package (a ZIP of CSV dumps of
 * TV Time's tables). The folders differ between packages, so files are
 * found by name wherever they lie; the tracking tables come in two
 * generations, told apart by their header:
 *
 * - `tracking-prod-records-v2.csv` keys every row (`key`): an episode
 *   viewing (`watch-episode-…`, `rewatch-episode-…`) with the TVDB ids of
 *   the episode and its show, or a show the user added (`user-series-…`);
 * - `tracking-prod-records.csv` names its rows by `type-uuid-n`: films
 *   watched or saved for later, and the episodes of packages older than v2.
 *
 * Shows followed before v2 flags existed are in `followed_tv_show.csv`,
 * ratings in `ratings-live-votes.csv`. TV Time is gone (July 2026); the
 * packages it handed out are all there will ever be.
 */
final class TvTimeArchive
{
    private const TRACKING_PREFIX = 'tracking-prod-records';

    private const FOLLOWED = 'followed_tv_show.csv';

    private const RATINGS = 'ratings-live-votes.csv';

    private readonly ZipExport $zip;

    public function __construct(string $path)
    {
        $this->zip = new ZipExport($path);
    }

    public function isValid(): bool
    {
        return $this->tracking(TvTimeTracking::Keyed) !== [] || $this->tracking(TvTimeTracking::Named) !== [];
    }

    /**
     * The rows of every tracking file of one generation.
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(TvTimeTracking $generation): Generator
    {
        foreach ($this->tracking($generation) as $csv) {
            yield from $csv->rows();
        }
    }

    /**
     * When a row of the older table was watched: an epoch in
     * `watch_date_range_key` ("watch-date-1649590015"), else the row's own
     * moment.
     *
     * @param  array<string, string>  $row
     */
    public static function watchedAt(array $row): ?CarbonImmutable
    {
        if (preg_match('/^watch-date-(\d+)$/', Payload::text($row, 'watch_date_range_key'), $epoch) === 1) {
            return CarbonImmutable::createFromTimestampUTC((int) $epoch[1]);
        }

        return Payload::instant(Payload::text($row, 'created_at'));
    }

    /** @return Generator<int, array<string, string>> */
    public function followed(): Generator
    {
        foreach ($this->named(self::FOLLOWED) as $entry) {
            yield from $this->zip->csv($entry, ['tv_show_id'])->rows();
        }
    }

    /** @return Generator<int, array<string, string>> */
    public function votes(): Generator
    {
        foreach ($this->named(self::RATINGS) as $entry) {
            yield from $this->zip->csv($entry, ['uuid', 'vote_key'])->rows();
        }
    }

    /**
     * The tracking files whose header marks them as this generation.
     *
     * @return list<CsvStream>
     */
    private function tracking(TvTimeTracking $generation): array
    {
        $files = [];

        foreach ($this->zip->names() as $entry) {
            $name = basename($entry);

            if (! str_starts_with($name, self::TRACKING_PREFIX) || ! str_ends_with($name, '.csv')) {
                continue;
            }

            $csv = $this->zip->csv($entry, [$generation->marker()]);

            if ($csv->isValid()) {
                $files[] = $csv;
            }
        }

        return $files;
    }

    /**
     * Every entry of that file name, in whatever folder.
     *
     * @return list<string>
     */
    private function named(string $file): array
    {
        return array_values(array_filter($this->zip->names(), fn (string $entry): bool => basename($entry) === $file));
    }
}

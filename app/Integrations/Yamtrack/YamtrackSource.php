<?php

namespace App\Integrations\Yamtrack;

use App\Catalog\MediaTypeRegistry;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Data\ImportDrop;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Generator;

/**
 * History, ratings and statuses from a Yamtrack CSV export. Yamtrack keys
 * items by source + media_type + media_id (+ season/episode number), which
 * translates to our provider + type + ref. Rows this instance has no provider
 * for (anime; games when IGDB is not configured) are skipped and tallied.
 *
 * Semantics per row type:
 * - episode: one play at end_date (fallback: start date, air date, now);
 * - movie: "Completed" logs a play; score → rating; status mapped below;
 * - tv / season: score → rating; watched state comes from episode rows,
 *   so "Completed" / "In progress" import nothing on the container itself;
 * - statuses: Paused → on hold, Dropped → dropped (roots only),
 *   Planning → watchlist (roots only: Yamtrack auto-creates "Planning"
 *   season rows for unreleased future seasons — here that state is derived,
 *   so importing them would just pollute the watchlist).
 */
final readonly class YamtrackSource implements ReadsFile
{
    public const CODE = 'yamtrack';

    /** Yamtrack media_type → VideTrack type code. */
    private const TYPE_MAP = ['tv' => 'show'];

    public function __construct(private MediaTypeRegistry $types) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.yamtrack_export_csv');
    }

    public function description(): string
    {
        return __('import.history_ratings_and_statuses_items_this');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_yamtrack');
    }

    public function accept(): string
    {
        return '.csv';
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if (! $csv->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_yamtrack'));
        }

        return ImportManifest::whole([new ManifestCategory('entries', __('import.all_entries'), $csv->count())]);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $lastViewings = $this->lastViewings($input);

        foreach ($this->csv($input)->entries() as $row) {
            yield $row instanceof SkipRow ? $row : $this->row($row, $lastViewings);
        }
    }

    /**
     * The latest viewing the file lists for each show, keyed "source:id". A
     * show's status (Dropped, Paused) is the one it has now, and Yamtrack
     * keeps no date of setting it — only that it came no earlier than the
     * last episode the file says was watched. Read in a pass of its own,
     * because episode rows may follow their show's.
     *
     * @return array<string, CarbonImmutable>
     */
    private function lastViewings(ImportInput $input): array
    {
        $latest = [];

        foreach ($this->csv($input)->rows() as $row) {
            $at = $row['media_type'] === 'episode' ? $this->watchedAt($row) : null;
            $key = "{$row['source']}:{$row['media_id']}";

            if ($at !== null && (! isset($latest[$key]) || $at->gt($latest[$key]))) {
                $latest[$key] = $at;
            }
        }

        return $latest;
    }

    private function csv(ImportInput $input): CsvStream
    {
        return new CsvStream((string) $input->path, ['media_id', 'source', 'media_type']);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, CarbonImmutable>  $lastViewings
     */
    private function row(array $row, array $lastViewings): MediaRow|SkipRow
    {
        $mediaType = $row['media_type'];
        $type = self::TYPE_MAP[$mediaType] ?? $mediaType;
        $label = ($row['title'] ?? '') !== '' ? $row['title'] : "{$mediaType}:{$row['media_id']}";
        $subject = $this->subject($row, $type, $label);

        if ($subject === null) {
            return new SkipRow('import.skip_yamtrack_no_media_id', ['label' => $label]);
        }

        // Unknown here: the subject alone lands in the report as skipped.
        if (! $this->types->has($type)) {
            return new MediaRow($subject);
        }

        if ($mediaType === 'episode') {
            return new MediaRow($subject, plays: [new ImportPlay($this->watchedAt($row))]);
        }

        $definition = $this->types->get($type);
        $score = (float) ($row['score'] ?? 0);
        $trackedAt = $this->trackedAt($row);
        $status = $row['status'] ?? '';
        $lastViewing = $lastViewings["{$row['source']}:{$row['media_id']}"] ?? null;
        // The status a show is in stands over the viewings the file lists.
        $statusAt = $lastViewing !== null && ($trackedAt === null || $lastViewing->gt($trackedAt)) ? $lastViewing : $trackedAt;

        // Yamtrack's progress column carries the counter in the same unit
        // the registry uses (games: minutes, books: pages, manga: chapters);
        // for other types (shows: episode tallies, movies: 0/1) it must
        // never leak into amounts — the backfill guards by progressUnit too.
        $progress = $definition->progressUnit === null ? null : (int) ($row['progress'] ?? 0);

        return new MediaRow(
            $subject,
            // Playable roots (movies, games): "Completed" logs a play; shows
            // derive their state from episode rows instead.
            plays: $status === 'Completed' && $definition->playable && $definition->isRoot()
                ? [new ImportPlay($this->watchedAt($row), amount: $progress)]
                : [],
            rating: $score >= 1 ? new ImportRating(min(10, (int) round($score)), $trackedAt) : null,
            status: match ($status) {
                'Paused' => new ImportStatus(MediaUserStatus::OnHold, $statusAt, $progress),
                // Shows derive their in-progress state from episode rows; only
                // flat progress types (games, books, manga, comics) store it as
                // a manual status — with the counter they were left at.
                'In progress' => $definition->progressUnit !== null
                    ? new ImportStatus(MediaUserStatus::InProgress, $trackedAt, $progress)
                    : null,
                default => null,
            },
            // Roots only: Yamtrack has no "skipped season", so a Dropped
            // season row is just the show's own drop mirrored onto its
            // container. Here a dropped season MEANS a skipped one and settles
            // every episode inside it — importing it reads a show abandoned at
            // 25 episodes as 79 of 79.
            drop: $status === 'Dropped' && $definition->isRoot() ? new ImportDrop($statusAt, $progress) : null,
            watchlist: $status === 'Planning' && $definition->isRoot() ? new ImportMark($trackedAt) : null,
        );
    }

    /**
     * When the entry was last touched at Yamtrack — the closest thing its
     * export has to "rated / added on". Backdates ratings, statuses and
     * watchlist entries so activity does not pile up on the import day.
     *
     * @param  array<string, string>  $row
     */
    private function trackedAt(array $row): ?CarbonImmutable
    {
        return $this->firstDate($row, ['progressed_at', 'created_at']);
    }

    /**
     * The play timestamp: Yamtrack's end_date, else start_date; null leaves
     * the item's own release date (MarkWatched's "release date" mode) to
     * the runner.
     *
     * @param  array<string, string>  $row
     */
    private function watchedAt(array $row): ?CarbonImmutable
    {
        return $this->firstDate($row, ['end_date', 'start_date']);
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $fields
     */
    private function firstDate(array $row, array $fields): ?CarbonImmutable
    {
        foreach ($fields as $field) {
            $date = Payload::instant($row[$field] ?? null);

            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Yamtrack ids are flat: a season or an episode names its show's id and
     * its numbers, and comes below that show.
     *
     * @param  array<string, string>  $row
     */
    private function subject(array $row, string $type, string $label): ?MediaSubject
    {
        $id = $row['media_id'];

        if ($id === '') {
            return null;
        }

        $path = match ($row['media_type']) {
            'season' => [$row['season_number'] ?? ''],
            'episode' => [$row['season_number'] ?? '', $row['episode_number'] ?? ''],
            default => [],
        };

        if ($path === []) {
            return MediaSubject::direct($type, $row['source'], $id, $label);
        }

        if (in_array('', $path, true)) {
            return null;
        }

        return MediaSubject::below('show', $row['source'], $id, array_map('intval', $path), $label);
    }
}

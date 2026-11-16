<?php

namespace App\Integrations\Hltb;

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Contracts\ExplainsSetup;
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
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\Playtime;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\PlayDating;
use App\Library\RatingScale;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Generator;

/**
 * A HowLongToBeat game list exported as CSV — HLTB has no API, and its own
 * export is what Yamtrack reads too. One row per playthrough: the status is
 * an "X" in one of Completed / Playing / Replay / Backlog / Retired, times
 * read "8:35:30", "46:30", seconds, or "--", and the review is 0–100.
 *
 * The export carries no ids and no release year, so a game is placed by its
 * title alone — and only when IGDB has a game of exactly that title. A row
 * with no status mark is reported, not guessed at (Yamtrack reads it as
 * completed). Two playthroughs of one game are two plays.
 */
final class HltbCsvSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'hltb';

    /** Status columns, in the order a mark wins. */
    private const STATUSES = ['completed', 'playing', 'replay', 'retired', 'backlog'];

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.howlongtobeat_export_csv');
    }

    public function description(): string
    {
        return __('import.games_you_finished_are_playing_retired');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_howlongtobeat');
    }

    public function accept(): string
    {
        return '.csv';
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.export_your_game_list_as_csv'))];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if (! $csv->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_howlongtobeat'));
        }

        return ImportManifest::whole([new ManifestCategory('games', __('common.games'), $csv->count())]);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        foreach ($this->csv($input)->entries() as $row) {
            yield $row instanceof SkipRow ? $row : $this->row($row);
        }
    }

    private function csv(ImportInput $input): CsvStream
    {
        return new CsvStream(
            (string) $input->path,
            ['title', 'completed', 'playing', 'backlog', 'retired', 'progress'],
            lowercase: true,
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    private function row(array $row): MediaRow|SkipRow
    {
        $title = trim($row['title']);
        $platform = trim($row['platform'] ?? '');
        $label = $platform === '' ? $title : "{$title} ({$platform})";
        $status = $this->status($row);

        if ($title === '') {
            return new SkipRow('import.skip_hltb_no_title');
        }

        if ($status === null) {
            return new SkipRow('import.skip_hltb_no_status', ['label' => $label]);
        }

        $minutes = $this->minutes($row);
        $updated = $this->date($row, 'updated');
        $completed = $this->date($row, 'completion date');
        $started = $this->date($row, 'start date');
        $rating = RatingScale::readPercent($row['review'] ?? '');

        return new MediaRow(
            MediaSubject::external(MediaKind::Game, [], $label, byTitle: new TitleQuery($title, exactTitle: true)),
            plays: $status === 'completed'
                ? [new ImportPlay($completed ?? $updated, amount: $minutes, dating: $completed === null ? PlayDating::Activity : PlayDating::Viewing)]
                : [],
            rating: $rating === null ? null : new ImportRating($rating, $updated),
            status: in_array($status, ['playing', 'replay'], true)
                ? new ImportStatus(MediaUserStatus::InProgress, $started ?? $updated, $minutes)
                : null,
            drop: $status === 'retired' ? new ImportDrop($updated, $minutes) : null,
            watchlist: $status === 'backlog' ? new ImportMark($this->date($row, 'added')) : null,
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    private function status(array $row): ?string
    {
        foreach (self::STATUSES as $column) {
            if (strtoupper(trim($row[$column] ?? '')) === 'X') {
                return $column;
            }
        }

        return null;
    }

    /**
     * Time spent, in minutes: the longest of the timers the user filled in.
     *
     * @param  array<string, string>  $row
     */
    private function minutes(array $row): ?int
    {
        $longest = 0;

        foreach (['progress', 'main story', 'main + extras', 'completionist'] as $column) {
            $longest = max($longest, Playtime::read($row[$column] ?? '') ?? 0);
        }

        return $longest > 0 ? $longest : null;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function date(array $row, string $column): ?CarbonImmutable
    {
        $value = $row[$column] ?? null;

        // HLTB writes "--" for a date it never had.
        return $value === '--' ? null : Payload::instant($value);
    }
}

<?php

namespace App\Integrations\Backloggd;

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\CsvStream;
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
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\RatingScale;
use App\Support\Payload;
use Generator;

/**
 * A Backloggd game list. Backloggd has no export of its own, so this reads
 * the two files community tools save, told apart by their header:
 *
 * - the Toolkittd browser add-on's CSV: one row per game with Backloggd's
 *   flags (played, playing, backlog, wishlist, liked), the first
 *   playthrough's rating and dates, and the time logged. "Played" does not
 *   say whether the game was finished or put aside, so it is a finished
 *   play, as Backloggd counts it among the played games;
 * - BackloggdExporter's CSV: a title and the rating in stars, nothing else.
 *
 * Neither names a year or an id, so games are found by their exact title.
 */
final class BackloggdCsvSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'backloggd';

    private const TOOLKITTD_COLUMNS = ['Game Name', 'Played', 'Currently Playing', 'In Backlog', 'In Wishlist', 'Liked', 'Rating'];

    private const EXPORTER_COLUMNS = ['Title', 'Rating'];

    private const MINUTES_PER_HOUR = 60;

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.backloggd_export_csv');
    }

    public function description(): string
    {
        return __('import.games_you_played_are_playing_keep');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_backloggd');
    }

    public function accept(): string
    {
        return '.csv';
    }

    public function notes(): array
    {
        return [
            new SetupNote(__('import.backloggd_has_no_export_of_its'), 'https://addons.mozilla.org/firefox/addon/toolkittd/'),
            new SetupNote(__('import.backloggdexporter_saves_only_titles_and'), 'https://github.com/Medpus/BackloggdExporter'),
        ];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if ($csv === null) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_backloggd'));
        }

        return ImportManifest::whole([new ManifestCategory('games', __('common.games'), $csv->count())]);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $csv = $this->csv($input);

        foreach ($csv?->entries() ?? [] as $row) {
            yield match (true) {
                $row instanceof SkipRow => $row,
                isset($row['Game Name']) => $this->logged($row),
                default => $this->rated($row),
            };
        }
    }

    /** The file read by the header it has; null when it has neither. */
    private function csv(ImportInput $input): ?CsvStream
    {
        foreach ([self::TOOLKITTD_COLUMNS, self::EXPORTER_COLUMNS] as $columns) {
            $csv = new CsvStream((string) $input->path, $columns);

            if ($csv->isValid()) {
                return $csv;
            }
        }

        return null;
    }

    /**
     * A Toolkittd row: everything Backloggd's flags say about the game.
     *
     * @param  array<string, string>  $row
     */
    private function logged(array $row): MediaRow|SkipRow
    {
        $name = trim($row['Game Name']);
        $played = $this->flag($row, 'Played') || $this->flag($row, 'Mastered');
        $playing = $this->flag($row, 'Currently Playing');
        $wanted = $this->flag($row, 'In Backlog') || $this->flag($row, 'In Wishlist');
        $liked = $this->flag($row, 'Liked');
        // Backloggd rates in half stars, kept as tenths: 1–10, our scale.
        $rating = RatingScale::readPoints($row['Rating']);

        if ($name === '') {
            return new SkipRow('import.skip_backloggd_no_title');
        }

        if (! $played && ! $playing && ! $wanted && ! $liked && $rating === null) {
            return new SkipRow('import.skip_backloggd_nothing_marked', ['label' => $name]);
        }

        $minutes = $this->minutes($row);
        $finished = Payload::instant($row['Finish Date'] ?? null);

        return new MediaRow(
            $this->subject($name),
            plays: $played ? [new ImportPlay($finished, amount: $minutes)] : [],
            rating: $rating === null ? null : new ImportRating($rating, $finished),
            status: $playing ? new ImportStatus(MediaUserStatus::InProgress, Payload::instant($row['Start Date'] ?? null), $minutes) : null,
            watchlist: $wanted ? new ImportMark : null,
            favorite: $liked ? new ImportMark : null,
        );
    }

    /**
     * A BackloggdExporter row: a title and its stars.
     *
     * @param  array<string, string>  $row
     */
    private function rated(array $row): MediaRow|SkipRow
    {
        $title = trim($row['Title']);
        $rating = RatingScale::readStars($row['Rating']);

        if ($title === '') {
            return new SkipRow('import.skip_backloggd_no_title');
        }

        if ($rating === null) {
            return new SkipRow('import.skip_backloggd_unrated', ['label' => $title]);
        }

        return new MediaRow($this->subject($title), rating: new ImportRating($rating));
    }

    private function subject(string $title): MediaSubject
    {
        return MediaSubject::external(MediaKind::Game, [], $title, byTitle: new TitleQuery($title, exactTitle: true));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function flag(array $row, string $column): bool
    {
        return mb_strtolower(Payload::text($row, $column)) === 'true';
    }

    /**
     * The time logged on the game, in minutes; null when none was.
     *
     * @param  array<string, string>  $row
     */
    private function minutes(array $row): ?int
    {
        $hours = Payload::text($row, 'Total Hours');
        $minutes = Payload::text($row, 'Total Minutes');
        $total = (ctype_digit($hours) ? (int) $hours : 0) * self::MINUTES_PER_HOUR + (ctype_digit($minutes) ? (int) $minutes : 0);

        return $total > 0 ? $total : null;
    }
}

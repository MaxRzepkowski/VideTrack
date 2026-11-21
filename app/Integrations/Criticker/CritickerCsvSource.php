<?php

namespace App\Integrations\Criticker;

use App\Catalog\ExternalIdSource;
use App\Catalog\ImdbId;
use App\Catalog\MediaKind;
use App\Integrations\Import\ColumnMap;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\ImportCategory;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\TitleLabel;
use App\Library\RatingScale;
use App\Support\Payload;
use Generator;

/**
 * Criticker's rankings exported as CSV: one row per film or show the user
 * scored, out of 100, with when. Criticker keeps no viewing history, so
 * the scores are what comes over; taking every scored film for a watched
 * one, undated, is the user's choice. A show is left out of that: watched
 * would mean every episode, which a score does not say. Titles are placed by their IMDb id,
 * then by title and year; a show is told from a film by its Criticker page.
 * Criticker pads some column names with spaces, which do not count.
 */
final class CritickerCsvSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'criticker';

    private const COLUMNS = [
        'score' => ['Score'],
        'title' => ['Film Name'],
        'year' => ['Year'],
        'rated' => ['Date Rated'],
        'url' => ['URL'],
        'imdb' => ['IMDB ID'],
    ];

    /** A show's Criticker page sits under /tv/, a film's under /film/. */
    private const SHOW_PAGE = '#criticker\.com/tv/#i';

    private const WATCHED = 'watched';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.criticker_rankings_csv');
    }

    public function description(): string
    {
        return __('import.the_films_and_shows_you_scored_on_criticker');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_criticker');
    }

    public function accept(): string
    {
        return '.csv';
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.on_criticker_open_resources_and_export'), 'https://www.criticker.com/resources/')];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if (! $csv->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_criticker'));
        }

        $scored = 0;
        $films = 0;
        $unreadable = 0;

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                $unreadable++;

                continue;
            }

            $scored++;
            $films += $this->kind($row) === MediaKind::Movie ? 1 : 0;
        }

        return ImportManifest::selectable([
            ImportCategory::Ratings->counted($scored),
            (new ManifestCategory(self::WATCHED, __('import.scored_films_as_watched_undated'), $films))->optIn(),
        ], unreadable: $unreadable);
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $rating = $selection->has(ImportCategory::Ratings->value);
        $watched = $selection->has(self::WATCHED);

        foreach ($this->csv($input)->entries() as $row) {
            if ($row instanceof SkipRow) {
                yield $row;

                continue;
            }

            $title = trim($row['title']);

            if ($title === '') {
                yield new SkipRow('import.skip_criticker_no_title');

                continue;
            }

            $subject = $this->subject($row, $title);

            if ($rating) {
                $score = $this->score($row['score']);

                yield $score === null
                    ? new SkipRow('import.skip_criticker_no_score', ['label' => $subject->label])
                    : new MediaRow($subject, rating: new ImportRating($score, Payload::instant(Payload::text($row, 'rated'))));
            }

            if ($watched && $this->kind($row) === MediaKind::Movie) {
                yield new MediaRow($subject, plays: [new ImportPlay]);
            }
        }
    }

    /** Out of 100; a 0 is Criticker's lowest score, not a missing one. */
    private function score(string $value): ?int
    {
        return trim($value) === '0' ? RatingScale::MIN : RatingScale::readPercent($value);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function kind(array $row): MediaKind
    {
        return preg_match(self::SHOW_PAGE, Payload::text($row, 'url')) === 1 ? MediaKind::Show : MediaKind::Movie;
    }

    private function csv(ImportInput $input): CsvStream
    {
        return CsvStream::mapped((string) $input->path, new ColumnMap(self::COLUMNS), ['score', 'title']);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function subject(array $row, string $title): MediaSubject
    {
        $imdb = ImdbId::read(Payload::text($row, 'imdb'));
        $year = Payload::yearOf(Payload::text($row, 'year'));

        return MediaSubject::external(
            $this->kind($row),
            $imdb === null ? [] : [ExternalIdSource::Imdb->value => $imdb],
            TitleLabel::of($title, $year),
            byTitle: new TitleQuery($title, $year),
        );
    }
}

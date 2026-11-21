<?php

namespace App\Integrations\Letterboxd;

use App\Catalog\MediaKind;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportListEntry;
use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportMark;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestList;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\SetupNote;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\ImportCategory;
use App\Integrations\Import\Rows\ListRow;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\TitleLabel;
use App\Library\PlayDating;
use App\Library\RatingScale;
use App\Support\Payload;
use Generator;

/**
 * A Letterboxd data export (a ZIP of CSV files, {@see LetterboxdArchive}).
 *
 * The diary is the viewing history, one row per viewing, rewatches included;
 * a film marked watched but never logged gets one play on the day it was
 * marked. Ratings, the watchlist and liked films come from their own files,
 * the user's lists opt-in.
 *
 * The export names films by title and year alone (its addresses are
 * Letterboxd's), so every film is placed by a search at the movie provider.
 */
final class LetterboxdZipSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'letterboxd';

    private const DIARY_COLUMNS = ['Watched Date'];

    private const RATING_COLUMNS = ['Rating'];

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.letterboxd_export_zip');
    }

    public function description(): string
    {
        return __('import.films_you_logged_rated_liked_and');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_letterboxd');
    }

    public function accept(): string
    {
        return '.zip';
    }

    public function notes(): array
    {
        return [
            new SetupNote(__('import.on_letterboxd_open_settings_import_export'), 'https://letterboxd.com/settings/data/'),
            new SetupNote(__('import.letterboxd_names_films_by_title_and')),
        ];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $archive = $this->archive($input);

        if (! $archive->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_a_letterboxd'));
        }

        $categories = [
            ImportCategory::History->counted($this->diary($archive)->count() + iterator_count($this->undiarized($archive))),
            ImportCategory::Ratings->counted($archive->csv(LetterboxdArchive::RATINGS, self::RATING_COLUMNS)->count()),
            ImportCategory::Watchlist->counted($archive->csv(LetterboxdArchive::WATCHLIST)->count()),
            ImportCategory::Favorites->counted($archive->csv(LetterboxdArchive::LIKED_FILMS)->count()),
        ];

        return ImportManifest::selectable($categories, array_map(
            fn (array $list): ManifestList => new ManifestList($list['entry'], $list['name'], $list['count'], false),
            $archive->lists(),
        ));
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $archive = $this->archive($input);

        if ($selection->has(ImportCategory::History->value)) {
            foreach ($this->diary($archive)->entries() as $row) {
                yield $row instanceof SkipRow ? $row : $this->film($row, fn (MediaSubject $subject): MediaRow => new MediaRow(
                    $subject,
                    plays: [new ImportPlay(Payload::instant($row['Watched Date']) ?? Payload::instant($row['Date']))],
                ));
            }

            // The day a film was marked watched is not the day it was seen:
            // a play that stands for the viewing, not one more of them.
            foreach ($this->undiarized($archive) as $row) {
                yield $row instanceof SkipRow ? $row : $this->film($row, fn (MediaSubject $subject): MediaRow => new MediaRow(
                    $subject,
                    plays: [new ImportPlay(Payload::instant($row['Date']), dating: PlayDating::Activity)],
                ));
            }
        }

        if ($selection->has(ImportCategory::Ratings->value)) {
            foreach ($archive->csv(LetterboxdArchive::RATINGS, self::RATING_COLUMNS)->entries() as $row) {
                yield $row instanceof SkipRow ? $row : $this->rated($row);
            }
        }

        if ($selection->has(ImportCategory::Watchlist->value)) {
            foreach ($archive->csv(LetterboxdArchive::WATCHLIST)->entries() as $row) {
                yield $row instanceof SkipRow ? $row : $this->film($row, fn (MediaSubject $subject): MediaRow => new MediaRow(
                    $subject,
                    watchlist: new ImportMark(Payload::instant($row['Date'])),
                ));
            }
        }

        if ($selection->has(ImportCategory::Favorites->value)) {
            foreach ($archive->csv(LetterboxdArchive::LIKED_FILMS)->entries() as $row) {
                yield $row instanceof SkipRow ? $row : $this->film($row, fn (MediaSubject $subject): MediaRow => new MediaRow(
                    $subject,
                    favorite: new ImportMark(Payload::instant($row['Date'])),
                ));
            }
        }

        foreach ($archive->lists() as $list) {
            if (in_array($list['entry'], $selection->listIds, true)) {
                yield from $this->list($archive, $list);
            }
        }
    }

    private function archive(ImportInput $input): LetterboxdArchive
    {
        return new LetterboxdArchive((string) $input->path);
    }

    private function diary(LetterboxdArchive $archive): CsvStream
    {
        return $archive->csv(LetterboxdArchive::DIARY, self::DIARY_COLUMNS);
    }

    /**
     * The rows of watched.csv for films the diary never logged, and the rows
     * it cannot read. Diary and watched name a film by title and year alike;
     * their Letterboxd addresses differ (a diary entry has its own).
     *
     * @return Generator<int, array<string, string>|SkipRow>
     */
    private function undiarized(LetterboxdArchive $archive): Generator
    {
        $logged = [];

        foreach ($this->diary($archive)->rows() as $row) {
            $logged[$this->filmKey($row)] = true;
        }

        foreach ($archive->csv(LetterboxdArchive::WATCHED)->entries() as $row) {
            if ($row instanceof SkipRow || ! isset($logged[$this->filmKey($row)])) {
                yield $row;
            }
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function rated(array $row): MediaRow|SkipRow
    {
        $rating = RatingScale::readStars($row['Rating']);

        if ($rating === null) {
            return new SkipRow('import.skip_letterboxd_no_rating', ['label' => $this->filmLabel($row)]);
        }

        return $this->film($row, fn (MediaSubject $subject): MediaRow => new MediaRow(
            $subject,
            rating: new ImportRating($rating, Payload::instant($row['Date'])),
        ));
    }

    /**
     * @param  array{entry: string, name: string, description: string|null, count: int}  $list
     * @return Generator<int, ListRow|MediaRow|SkipRow>
     */
    private function list(LetterboxdArchive $archive, array $list): Generator
    {
        $ref = new ImportListRef($list['name'], description: $list['description']);

        yield new ListRow($ref);

        foreach ($archive->listFilms($list['entry']) as $row) {
            yield $row instanceof SkipRow ? $row : $this->film($row, fn (MediaSubject $subject): MediaRow => new MediaRow($subject, lists: [new ImportListEntry($ref)]));
        }
    }

    /**
     * A film row placed by its title and year, handed to $build; a skip for
     * a row without a title.
     *
     * @param  array<string, string>  $row
     * @param  callable(MediaSubject): MediaRow  $build
     */
    private function film(array $row, callable $build): MediaRow|SkipRow
    {
        $name = Payload::text($row, 'Name');

        if ($name === '') {
            return new SkipRow('import.skip_letterboxd_no_name');
        }

        return $build(MediaSubject::external(
            MediaKind::Movie,
            [],
            $this->filmLabel($row),
            byTitle: new TitleQuery($name, $this->year($row)),
        ));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function filmLabel(array $row): string
    {
        $year = $this->year($row);

        return TitleLabel::of(Payload::text($row, 'Name'), $year);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function year(array $row): ?int
    {
        $year = Payload::text($row, 'Year');

        return ctype_digit($year) ? (int) $year : null;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function filmKey(array $row): string
    {
        return Payload::text($row, 'Name').'|'.Payload::text($row, 'Year');
    }
}

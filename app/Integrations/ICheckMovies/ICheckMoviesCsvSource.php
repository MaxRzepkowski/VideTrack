<?php

namespace App\Integrations\ICheckMovies;

use App\Catalog\ExternalIdSource;
use App\Catalog\ImdbId;
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
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Generator;

/**
 * An iCheckMovies export (CSV, comma- or semicolon-separated as the account
 * sets it): the films of the user's checks, favorites, watchlist, or of one
 * list. Every row says the same about its film: when it was checked, and
 * yes or no to favorite and watchlist. A check is dated by when it was
 * made, not when the film was seen.
 *
 * An export of a list ranks its films; it comes over as a list of its own,
 * named as the file was. Films are placed by their IMDb id, then by title
 * and year.
 */
final class ICheckMoviesCsvSource implements ExplainsSetup, ReadsFile
{
    public const CODE = 'icheckmovies';

    /** The key of the one list an export of a list holds. */
    private const LIST = 'list';

    /** The name of that list when the file's own is lost. */
    private const LIST_NAME = 'iCheckMovies';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return __('import.icheckmovies_export_csv');
    }

    public function description(): string
    {
        return __('import.films_you_checked_favorited_or_keep');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_icheckmovies');
    }

    public function accept(): string
    {
        return '.csv';
    }

    public function notes(): array
    {
        return [new SetupNote(__('import.on_icheckmovies_open_your_checks_or'), 'https://www.icheckmovies.com/movies/checked/')];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        $csv = $this->csv($input);

        if (! $csv->isValid()) {
            return ImportManifest::invalid(__('import.the_file_is_not_an_icheckmovies'));
        }

        $checked = 0;
        $favorites = 0;
        $watchlist = 0;
        $ranked = 0;
        $unreadable = 0;

        foreach ($csv->entries() as $row) {
            if ($row instanceof SkipRow) {
                $unreadable++;

                continue;
            }

            $checked += $this->checked($row) ? 1 : 0;
            $favorites += Payload::flag($row, 'favorite') ? 1 : 0;
            $watchlist += Payload::flag($row, 'watchlist') ? 1 : 0;
            $ranked += isset($row['rank']) ? 1 : 0;
        }

        return ImportManifest::selectable(
            [
                ImportCategory::History->counted($checked),
                ImportCategory::Favorites->counted($favorites),
                ImportCategory::Watchlist->counted($watchlist),
            ],
            $ranked === 0 ? [] : [new ManifestList(self::LIST, $this->listName($input), $ranked, false)],
            $unreadable,
        );
    }

    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $list = in_array(self::LIST, $selection->listIds, true) ? new ImportListRef($this->listName($input)) : null;

        if ($list !== null) {
            yield new ListRow($list);
        }

        foreach ($this->csv($input)->entries() as $row) {
            if ($row instanceof SkipRow) {
                yield $row;

                continue;
            }

            $subject = $this->subject($row);

            if ($subject === null) {
                yield new SkipRow('import.skip_icheckmovies_no_title');

                continue;
            }

            if ($selection->has(ImportCategory::History->value) && $this->checked($row)) {
                yield new MediaRow($subject, plays: [new ImportPlay($this->checkedAt($row), dating: PlayDating::Activity)]);
            }

            if ($selection->has(ImportCategory::Favorites->value) && Payload::flag($row, 'favorite')) {
                yield new MediaRow($subject, favorite: new ImportMark);
            }

            if ($selection->has(ImportCategory::Watchlist->value) && Payload::flag($row, 'watchlist')) {
                yield new MediaRow($subject, watchlist: new ImportMark);
            }

            if ($list !== null && isset($row['rank'])) {
                yield new MediaRow($subject, lists: [new ImportListEntry($list)]);
            }
        }
    }

    private function csv(ImportInput $input): CsvStream
    {
        return new CsvStream((string) $input->path, ['title', 'year', 'imdburl', 'checked'], lowercase: true);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function subject(array $row): ?MediaSubject
    {
        $title = trim($row['title']);

        if ($title === '') {
            return null;
        }

        $imdb = ImdbId::read($row['imdburl']);
        $year = Payload::yearOf(trim($row['year']));

        return MediaSubject::external(
            MediaKind::Movie,
            $imdb === null ? [] : [ExternalIdSource::Imdb->value => $imdb],
            TitleLabel::of($title, $year),
            byTitle: new TitleQuery($title, $year),
        );
    }

    /**
     * "checked" holds the moment of the check, or "no".
     *
     * @param  array<string, string>  $row
     */
    private function checked(array $row): bool
    {
        return $this->checkedAt($row) !== null || Payload::flag($row, 'checked');
    }

    /**
     * @param  array<string, string>  $row
     */
    private function checkedAt(array $row): ?CarbonImmutable
    {
        $value = trim($row['checked']);

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? Payload::instant($value) : null;
    }

    /** The list as the file was called, without its extension. */
    private function listName(ImportInput $input): string
    {
        $name = trim(pathinfo((string) $input->fileName, PATHINFO_FILENAME));

        return $name === '' ? self::LIST_NAME : $name;
    }
}

<?php

namespace App\Integrations\Letterboxd;

use App\Integrations\Import\CsvStream;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\Import\ZipExport;
use App\Support\Payload;
use Generator;

/**
 * Read-only view over a Letterboxd data export (Settings → Import & Export):
 * a ZIP of CSV files, read where they lie. Only the files at the archive
 * root and in `lists/` are the user's library — `deleted/` and `orphaned/`
 * hold a diary and lists of their own, of entries the user removed.
 *
 * A list file opens with a note of its own: a version line, the list's
 * header and row, an empty line, and only then the films.
 */
final class LetterboxdArchive
{
    public const DIARY = 'diary.csv';

    public const WATCHED = 'watched.csv';

    public const RATINGS = 'ratings.csv';

    public const WATCHLIST = 'watchlist.csv';

    public const LIKED_FILMS = 'likes/films.csv';

    /** Every file of films carries these; the diary adds its own. */
    public const FILM_COLUMNS = ['Date', 'Name', 'Year'];

    private const LIST_DIRECTORY = 'lists/';

    /** The version line before the list's own header. */
    private const LIST_NOTE = 1;

    /** The version line, the list's header and row, and the empty line after them. */
    private const LIST_PREAMBLE = 4;

    private readonly ZipExport $zip;

    /** @var list<array{entry: string, name: string, description: string|null, count: int}>|null */
    private ?array $lists = null;

    public function __construct(string $path)
    {
        $this->zip = new ZipExport($path);
    }

    /** Every export carries the films marked watched, even an account with no diary. */
    public function isValid(): bool
    {
        return $this->csv(self::WATCHED)->isValid();
    }

    /**
     * A film file at the root; an absent one reads as no rows.
     *
     * @param  list<string>  $extraColumns
     */
    public function csv(string $file, array $extraColumns = []): CsvStream
    {
        return $this->zip->csv($file, [...self::FILM_COLUMNS, ...$extraColumns]);
    }

    /**
     * The user's lists, by the file each is in, named as on Letterboxd.
     *
     * @return list<array{entry: string, name: string, description: string|null, count: int}>
     */
    public function lists(): array
    {
        if ($this->lists !== null) {
            return $this->lists;
        }

        $lists = [];

        foreach ($this->zip->names() as $entry) {
            $films = $this->isListFile($entry) ? $this->films($entry) : null;
            $about = $films?->isValid() === true ? $this->about($entry) : null;

            if ($films !== null && $about !== null) {
                $lists[] = ['entry' => $entry, ...$about, 'count' => $films->count()];
            }
        }

        return $this->lists = $lists;
    }

    /**
     * The films of one list in their Letterboxd order, and the rows of it
     * that cannot be read.
     *
     * @return Generator<int, array<string, string>|SkipRow>
     */
    public function listFilms(string $entry): Generator
    {
        if ($this->isListFile($entry)) {
            yield from $this->films($entry)->entries();
        }
    }

    /** A list of the user's own: right under `lists/`, not one in `deleted/lists/`. */
    private function isListFile(string $entry): bool
    {
        return str_starts_with($entry, self::LIST_DIRECTORY)
            && str_ends_with($entry, '.csv')
            && ! str_contains(substr($entry, strlen(self::LIST_DIRECTORY)), '/');
    }

    private function films(string $entry): CsvStream
    {
        return $this->zip->csv($entry, ['Position', 'Name', 'Year'], preamble: self::LIST_PREAMBLE);
    }

    /**
     * The list's name and description, from the row under the version line;
     * null for a list without a name.
     *
     * @return array{name: string, description: string|null}|null
     */
    private function about(string $entry): ?array
    {
        foreach ($this->zip->csv($entry, ['Date', 'Name', 'URL'], preamble: self::LIST_NOTE)->rows() as $row) {
            $name = Payload::text($row, 'Name');

            return $name === '' ? null : ['name' => $name, 'description' => Payload::string($row, 'Description')];
        }

        return null;
    }
}

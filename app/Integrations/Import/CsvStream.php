<?php

namespace App\Integrations\Import;

use App\Integrations\Import\Rows\SkipRow;
use Generator;

/**
 * A CSV export read one header-keyed row at a time. fgetcsv, so quoted fields
 * may span lines (Yamtrack notes do), and the file is never held in memory in
 * full — a heavy export is tens of MB. A row whose width does not match the
 * header is malformed: rows() passes over it, entries() reports it by number
 * so the import says something is missing. A UTF-8 byte order mark before
 * the first column is not part of its name.
 *
 * Exports differ in what the format leaves open, and the reader takes each
 * from the file rather than from the source: the separator is the comma,
 * tab or semicolon the header uses most (spreadsheets in many locales save
 * semicolons, some exporters tabs); a file opening with a UTF-16 byte order
 * mark is read as UTF-16; a value that is not UTF-8 is read as Windows-1252,
 * which exporters writing "UTF-8, mostly" fall back to. A header whose names
 * vary (by language, by version) is read through a {@see ColumnMap}.
 *
 * A file inside an uploaded ZIP is read where it lies, never unpacked to
 * disk: {@see inArchive()}.
 */
final readonly class CsvStream
{
    /**
     * RFC 4180: a quote inside a field is doubled, and nothing else escapes.
     * PHP's default backslash would read `…\"` at the end of a note as an
     * escaped quote and glue the next rows onto it.
     */
    public const NO_ESCAPE = '';

    private const UTF8_BOM = "\xEF\xBB\xBF";

    /** Byte order mark => the encoding it opens. */
    private const UTF16_BOMS = ["\xFF\xFE" => 'UTF-16LE', "\xFE\xFF" => 'UTF-16BE'];

    /** The separators a header is tried with, the comma first: it wins a tie. */
    private const SEPARATORS = [',', "\t", ';'];

    /**
     * @param  list<string>  $requiredColumns  a header missing any of these is
     *                                         not the export we expect
     * @param  bool  $lowercase  key rows by lowercased column names, for
     *                           exports whose header casing has drifted
     */
    public function __construct(
        private string $path,
        private array $requiredColumns,
        private bool $lowercase = false,
        private ?string $entry = null,
        private int $preamble = 0,
        private ?ColumnMap $columns = null,
    ) {}

    /**
     * A file whose columns go by several names, keyed by the map's own.
     *
     * @param  list<string>  $requiredColumns  as the map names them
     */
    public static function mapped(string $path, ColumnMap $columns, array $requiredColumns): self
    {
        return new self($path, $requiredColumns, columns: $columns);
    }

    /**
     * One file of a ZIP export, by its path inside the archive.
     *
     * @param  list<string>  $requiredColumns
     * @param  int  $preamble  records before the header, for an export that
     *                         opens with a note of its own (a version line,
     *                         a summary row)
     */
    public static function inArchive(string $archive, string $entry, array $requiredColumns, bool $lowercase = false, int $preamble = 0): self
    {
        return new self($archive, $requiredColumns, $lowercase, $entry, $preamble);
    }

    public function isValid(): bool
    {
        $opened = $this->open();

        if ($opened === null) {
            return false;
        }

        fclose($opened[0]);

        return true;
    }

    /** Every data row, malformed ones too — each is one row of the import — counted in one streaming pass; empty lines are none. */
    public function count(): int
    {
        $count = 0;

        foreach ($this->entries() as $ignored) {
            $count++;
        }

        return $count;
    }

    /**
     * The well-formed rows, for a pass that only reads.
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(): Generator
    {
        foreach ($this->entries() as $row) {
            if (! $row instanceof SkipRow) {
                yield $row;
            }
        }
    }

    /**
     * Every data row in file order: the row keyed by column, or a skip for a
     * malformed one, numbered as a spreadsheet shows the file (the header is
     * row 1). An empty line is nobody's entry and passes unremarked — a
     * file saved from a spreadsheet often ends with one.
     *
     * @return Generator<int, array<string, string>|SkipRow>
     */
    public function entries(): Generator
    {
        $opened = $this->open();

        if ($opened === null) {
            return;
        }

        [$stream, $header, $separator] = $opened;
        $number = $this->preamble + 1;

        try {
            while (($values = fgetcsv($stream, separator: $separator, escape: self::NO_ESCAPE)) !== false) {
                $number++;

                if ($values === [null]) {
                    continue;
                }

                yield count($values) === count($header)
                    ? array_combine($header, array_map(self::text(...), $values))
                    : new SkipRow('import.skip_csv_row_malformed', ['row' => $number]);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * The live stream plus the parsed header and the separator it uses; null
     * when the file is missing or its header lacks a required column. Caller
     * closes the stream.
     *
     * @return array{0: resource, 1: list<string>, 2: string}|null
     */
    private function open(): ?array
    {
        $stream = $this->stream();

        if ($stream === null) {
            return null;
        }

        for ($skipped = 0; $skipped < $this->preamble; $skipped++) {
            fgetcsv($stream, escape: self::NO_ESCAPE);
        }

        $line = fgets($stream);
        $line = $line === false ? '' : rtrim($line, "\r\n");

        if (str_starts_with($line, self::UTF8_BOM)) {
            $line = substr($line, strlen(self::UTF8_BOM));
        }

        $separator = self::separator($line);
        $header = $line === '' ? [] : array_map(self::text(...), str_getcsv($line, $separator, escape: self::NO_ESCAPE));

        if ($this->lowercase) {
            $header = array_map('mb_strtolower', $header);
        }

        if ($this->columns !== null) {
            $header = $this->columns->rename($header);
        }

        if ($header === [] || array_diff($this->requiredColumns, $header) !== []) {
            fclose($stream);

            return null;
        }

        return [$stream, $header, $separator];
    }

    /**
     * The file as UTF-8 from its first byte: a UTF-16 one is converted as it
     * is read, its byte order mark consumed. The mark is looked at on a
     * stream of its own, as two bytes read can no longer be given back.
     *
     * @return resource|null
     */
    private function stream()
    {
        $peek = $this->raw();

        if ($peek === null) {
            return null;
        }

        $encoding = self::UTF16_BOMS[(string) fread($peek, 2)] ?? null;
        fclose($peek);

        $stream = $this->raw();

        if ($stream !== null && $encoding !== null) {
            fread($stream, 2);
            stream_filter_append($stream, "convert.iconv.{$encoding}/UTF-8", STREAM_FILTER_READ);
        }

        return $stream;
    }

    /** @return resource|null */
    private function raw()
    {
        return $this->entry === null ? $this->file() : $this->archived($this->entry);
    }

    /**
     * The separator the header line uses most, quoted names aside; a header
     * of one column is comma-separated.
     */
    private static function separator(string $line): string
    {
        $unquoted = (string) preg_replace('/"[^"]*"/', '', $line);
        $separator = self::SEPARATORS[0];
        $most = 0;

        foreach (self::SEPARATORS as $candidate) {
            $count = substr_count($unquoted, $candidate);

            if ($count > $most) {
                $separator = $candidate;
                $most = $count;
            }
        }

        return $separator;
    }

    /** A value as UTF-8: one that is not is a Windows-1252 byte read as such. */
    private static function text(?string $value): string
    {
        $value = (string) $value;

        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /** @return resource|null */
    private function file()
    {
        $stream = is_file($this->path) ? fopen($this->path, 'r') : false;

        return $stream === false ? null : $stream;
    }

    /**
     * The entry through the zip wrapper, which opens an archive handle of its
     * own: a stream taken from a ZipArchive dies with that object's handle.
     *
     * @return resource|null
     */
    private function archived(string $entry)
    {
        $stream = (new ZipExport($this->path))->has($entry) ? fopen("zip://{$this->path}#{$entry}", 'r') : false;

        return $stream === false ? null : $stream;
    }
}

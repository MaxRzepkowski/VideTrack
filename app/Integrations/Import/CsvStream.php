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
 */
final readonly class CsvStream
{
    /**
     * RFC 4180: a quote inside a field is doubled, and nothing else escapes.
     * PHP's default backslash would read `…\"` at the end of a note as an
     * escaped quote and glue the next rows onto it.
     */
    public const NO_ESCAPE = '';

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
    ) {}

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

        [$stream, $header] = $opened;
        $number = 1;

        try {
            while (($values = fgetcsv($stream, escape: self::NO_ESCAPE)) !== false) {
                $number++;

                if ($values === [null]) {
                    continue;
                }

                yield count($values) === count($header)
                    ? array_combine($header, array_map(fn (?string $v) => (string) $v, $values))
                    : new SkipRow('import.skip_csv_row_malformed', ['row' => $number]);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * The live stream plus the parsed header; null when the file is missing
     * or its header lacks a required column. Caller closes the stream.
     *
     * @return array{0: resource, 1: list<string>}|null
     */
    private function open(): ?array
    {
        $stream = is_file($this->path) ? fopen($this->path, 'r') : false;

        if ($stream === false) {
            return null;
        }

        $header = fgetcsv($stream, escape: self::NO_ESCAPE);
        $header = $header === false ? [] : array_map(fn (?string $column) => (string) $column, $header);

        if ($header !== []) {
            $header[0] = (string) preg_replace('/^\x{FEFF}/u', '', $header[0]);
        }

        if ($this->lowercase) {
            $header = array_map('mb_strtolower', $header);
        }

        if ($header === [] || array_diff($this->requiredColumns, $header) !== []) {
            fclose($stream);

            return null;
        }

        return [$stream, $header];
    }
}

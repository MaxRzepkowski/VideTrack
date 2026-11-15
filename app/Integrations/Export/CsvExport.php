<?php

namespace App\Integrations\Export;

use App\Integrations\Import\CsvStream;
use App\Models\User;

/**
 * A download that is one CSV table: a header and a row per entry, written as
 * the rows are read. A cell a spreadsheet would run as a formula (starting
 * with = + - @, a tab or a carriage return) gets a leading apostrophe, so a
 * title or a list name from a user or a provider cannot execute on open.
 */
abstract class CsvExport implements ExportFormat
{
    /**
     * @return list<string>
     */
    abstract protected function header(): array;

    /**
     * @return iterable<list<string|int|null>>
     */
    abstract protected function rows(User $user): iterable;

    public function extension(): string
    {
        return 'csv';
    }

    public function contentType(): string
    {
        return 'text/csv';
    }

    public function part(): string
    {
        return $this->code();
    }

    public function write(User $user, $out): void
    {
        fputcsv($out, $this->header(), escape: CsvStream::NO_ESCAPE);

        foreach ($this->rows($user) as $row) {
            fputcsv($out, array_map($this->neutralizeFormula(...), $row), escape: CsvStream::NO_ESCAPE);
        }
    }

    private function neutralizeFormula(string|int|null $value): string|int|null
    {
        if (is_string($value) && $value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'".$value;
        }

        return $value;
    }
}

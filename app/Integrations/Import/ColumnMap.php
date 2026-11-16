<?php

namespace App\Integrations\Import;

/**
 * The names one column of an export goes by, for a file whose header is not
 * fixed: written in the language of the exporting app (Playnite), or padded
 * and recased between versions. A header names a column by any of its
 * aliases, case and surrounding spaces aside; the row then keys it by the
 * name this map gives it.
 */
final readonly class ColumnMap
{
    /**
     * @param  array<string, list<string>>  $aliases  the name a row keys the column by => every
     *                                                header name the column appears under
     */
    public function __construct(private array $aliases) {}

    /**
     * The header with each column the map knows under its own name; any
     * other column keeps the name it came with.
     *
     * @param  list<string>  $header
     * @return list<string>
     */
    public function rename(array $header): array
    {
        $names = [];

        foreach ($this->aliases as $name => $aliases) {
            foreach ($aliases as $alias) {
                $names[self::fold($alias)] = $name;
            }
        }

        return array_map(fn (string $column): string => $names[self::fold($column)] ?? $column, $header);
    }

    private static function fold(string $column): string
    {
        return mb_strtolower(trim($column));
    }
}

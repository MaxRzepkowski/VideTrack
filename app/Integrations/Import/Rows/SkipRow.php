<?php

namespace App\Integrations\Import\Rows;

/**
 * An entry the source already knows it cannot place — a row without an id,
 * a play without a date. Counted as skipped; why is an `import.*` key the
 * run words in the user's language, naming the entry, never quoting it.
 */
final readonly class SkipRow implements ImportRow
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(
        public string $key,
        public array $replace = [],
    ) {}
}

<?php

namespace App\Integrations\Import;

use App\Integrations\Import\Contracts\ImportSource;
use App\Support\ServiceRegistry;

/**
 * The import sources this instance takes in — each behind what it needs
 * (Goodreads a book type, HLTB a game type), so the import page offers only
 * what can actually be placed.
 *
 * @extends ServiceRegistry<ImportSource>
 */
final class ImportSourceRegistry extends ServiceRegistry
{
    /** The task run an import from the source is recorded under, its one spelling. */
    public static function taskName(string $code): string
    {
        return "import-{$code}";
    }

    protected function kind(): string
    {
        return 'import source';
    }
}

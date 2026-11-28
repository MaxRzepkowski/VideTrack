<?php

namespace App\Integrations\Import;

use App\Integrations\Import\Contracts\ImportSource;
use App\Integrations\Import\Contracts\ReadsFromConnection;
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

    /** The source that reads through a connection to $service, if one does. */
    public function readingThrough(string $service): ?ReadsFromConnection
    {
        foreach ($this->all() as $source) {
            if ($source instanceof ReadsFromConnection && $source->connectionService() === $service) {
                return $source;
            }
        }

        return null;
    }

    /**
     * The sources that read through an account the user connects in
     * Integrations, where their cards live.
     *
     * @return list<ReadsFromConnection>
     */
    public function readingFromConnection(): array
    {
        return array_values(array_filter($this->all(), fn (ImportSource $source): bool => $source instanceof ReadsFromConnection));
    }

    /**
     * The sources the import page offers: an export file or a form, run by
     * hand. One reading through an account lives in Integrations.
     *
     * @return list<ImportSource>
     */
    public function fromFileOrForm(): array
    {
        return array_values(array_filter($this->all(), fn (ImportSource $source): bool => ! $source instanceof ReadsFromConnection));
    }

    protected function kind(): string
    {
        return 'import source';
    }
}

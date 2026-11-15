<?php

namespace App\Integrations\Goodreads;

use App\Catalog\MediaTypeRegistry;
use App\Integrations\Export\ExportFormatRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Goodreads' CSV library file, both ways: its export read in, the books written out in it.
 */
final class GoodreadsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            // Books are placed by ISBN at the book provider; without it a
            // Goodreads export has nowhere to go.
            if ($this->app->make(MediaTypeRegistry::class)->has('book')) {
                $sources->register(GoodreadsCsvSource::CODE, GoodreadsCsvSource::class);
            }
        });

        $this->callAfterResolving(ExportFormatRegistry::class, function (ExportFormatRegistry $formats): void {
            // Without books there is nothing to write.
            if ($this->app->make(MediaTypeRegistry::class)->has('book')) {
                $formats->register(GoodreadsExport::CODE, GoodreadsExport::class);
            }
        });
    }
}

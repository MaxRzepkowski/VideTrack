<?php

namespace App\Integrations\Imdb;

use App\Integrations\Export\ExportFormatRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * IMDb's ratings and watchlist CSV, read in and written out.
 */
final class ImdbServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(ImdbCsvSource::CODE, ImdbCsvSource::class));
        $this->callAfterResolving(ExportFormatRegistry::class, fn (ExportFormatRegistry $formats) => $formats->register(ImdbExport::CODE, ImdbExport::class));
    }
}

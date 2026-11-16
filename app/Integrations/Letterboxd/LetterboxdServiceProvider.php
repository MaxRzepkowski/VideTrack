<?php

namespace App\Integrations\Letterboxd;

use App\Integrations\Export\ExportFormatRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Letterboxd both ways: its data export read in, its import CSV as a download.
 */
final class LetterboxdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(LetterboxdZipSource::CODE, LetterboxdZipSource::class));
        $this->callAfterResolving(ExportFormatRegistry::class, fn (ExportFormatRegistry $formats) => $formats->register(LetterboxdExport::CODE, LetterboxdExport::class));
    }
}

<?php

namespace App\Integrations\Letterboxd;

use App\Integrations\Export\ExportFormatRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Letterboxd's import CSV, as a download.
 */
final class LetterboxdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ExportFormatRegistry::class, fn (ExportFormatRegistry $formats) => $formats->register(LetterboxdExport::CODE, LetterboxdExport::class));
    }
}

<?php

namespace App\Integrations\Criticker;

use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Criticker's rankings, as Criticker exports them, read in.
 */
final class CritickerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(CritickerCsvSource::CODE, CritickerCsvSource::class));
    }
}

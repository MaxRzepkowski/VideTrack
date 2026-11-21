<?php

namespace App\Integrations\KOReader;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * KOReader's reading statistics, read in.
 */
final class KOReaderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            // Books are placed at the book provider; without it the statistics have nowhere to go.
            if ($this->app->make(MediaTypeRegistry::class)->has(MediaKind::Book->value)) {
                $sources->register(KOReaderSource::CODE, KOReaderSource::class);
            }
        });
    }
}

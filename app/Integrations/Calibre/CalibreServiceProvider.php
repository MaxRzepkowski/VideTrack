<?php

namespace App\Integrations\Calibre;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A Calibre library's catalog file read in.
 */
final class CalibreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            // Books are an optional module.
            if ($this->app->make(MediaTypeRegistry::class)->has(MediaKind::Book->value)) {
                $sources->register(CalibreSource::CODE, CalibreSource::class);
            }
        });
    }
}

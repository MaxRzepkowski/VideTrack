<?php

namespace App\Integrations\GogGalaxy;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A GOG Galaxy library, as a community script saves it, read in.
 */
final class GogGalaxyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            if ($this->app->make(MediaTypeRegistry::class)->has(MediaKind::Game->value)) {
                $sources->register(GogGalaxyCsvSource::CODE, GogGalaxyCsvSource::class);
            }
        });
    }
}

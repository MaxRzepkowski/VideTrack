<?php

namespace App\Integrations\AnimePlanet;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * An Anime-Planet manga list, as Anime-Planet exports it, read in.
 */
final class AnimePlanetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            // Manga is an optional module.
            if ($this->app->make(MediaTypeRegistry::class)->has(MediaKind::Manga->value)) {
                $sources->register(AnimePlanetJsonSource::CODE, AnimePlanetJsonSource::class);
            }
        });
    }
}

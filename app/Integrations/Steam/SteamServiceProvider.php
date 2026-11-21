<?php

namespace App\Integrations\Steam;

use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A Steam library, read with the user's own Steam key.
 */
final class SteamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            // The games are placed by their app id at the game provider.
            if ($this->app->make(MediaTypeRegistry::class)->has('game')) {
                $sources->register(SteamSource::CODE, SteamSource::class);
            }
        });
    }
}

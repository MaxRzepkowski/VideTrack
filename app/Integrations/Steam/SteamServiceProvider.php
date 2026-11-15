<?php

namespace App\Integrations\Steam;

use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A Steam library, read with the instance's Steam key.
 */
final class SteamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            // A Steam library needs the instance's Steam key, and the game
            // provider to place the games by their app id.
            if ($this->app->make(MediaTypeRegistry::class)->has('game') && filled(config('tracker.steam.api_key'))) {
                $sources->register(SteamSource::CODE, SteamSource::class);
            }
        });
    }
}

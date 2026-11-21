<?php

namespace App\Integrations\Tmdb;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A TMDB account, connected once and imported from without a file. It
 * needs nothing beyond the instance's TMDB key: offered whenever movies and
 * shows come from TMDB here, so a TMDB id is a ref this instance can open.
 */
final class TmdbAccountServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            if ($this->configured()) {
                $sources->register(TmdbAccountSource::CODE, TmdbAccountSource::class);
            }
        });
        $this->callAfterResolving(ConnectionRegistry::class, function (ConnectionRegistry $services): void {
            if ($this->configured()) {
                $services->register(TmdbService::CODE, TmdbService::class);
            }
        });
    }

    private function configured(): bool
    {
        $types = $this->app->make(MediaTypeRegistry::class);

        foreach ([MediaKind::Movie, MediaKind::Show] as $kind) {
            if (! $types->has($kind->value) || $types->get($kind->value)->provider !== 'tmdb') {
                return false;
            }
        }

        return true;
    }
}

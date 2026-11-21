<?php

namespace App\Integrations\MangaBaka;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A MangaBaka library, connected once and imported from without a file.
 * Offered when the operator registered the web app it authorizes against,
 * and when manga come from MyAnimeList here, whose ids MangaBaka keeps.
 */
final class MangaBakaLibraryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            if ($this->configured()) {
                $sources->register(MangaBakaSource::CODE, MangaBakaSource::class);
            }
        });
        $this->callAfterResolving(ConnectionRegistry::class, function (ConnectionRegistry $services): void {
            if ($this->configured()) {
                $services->register(MangaBakaService::CODE, MangaBakaService::class);
            }
        });
    }

    private function configured(): bool
    {
        $types = $this->app->make(MediaTypeRegistry::class);

        return filled(config('tracker.mangabaka.client_id'))
            && filled(config('tracker.mangabaka.client_secret'))
            && $types->has(MediaKind::Manga->value)
            && $types->get(MediaKind::Manga->value)->provider === 'mal';
    }
}

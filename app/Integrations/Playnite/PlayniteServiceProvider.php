<?php

namespace App\Integrations\Playnite;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A Playnite library, as its exporter add-on saves it, read in.
 */
final class PlayniteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            if ($this->app->make(MediaTypeRegistry::class)->has(MediaKind::Game->value)) {
                $sources->register(PlayniteCsvSource::CODE, PlayniteCsvSource::class);
            }
        });
    }
}

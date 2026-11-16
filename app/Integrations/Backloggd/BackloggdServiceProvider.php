<?php

namespace App\Integrations\Backloggd;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Backloggd game lists as community tools save them, read in.
 */
final class BackloggdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            // Backloggd names games by title only; the game provider places them.
            if ($this->app->make(MediaTypeRegistry::class)->has(MediaKind::Game->value)) {
                $sources->register(BackloggdCsvSource::CODE, BackloggdCsvSource::class);
            }
        });
    }
}

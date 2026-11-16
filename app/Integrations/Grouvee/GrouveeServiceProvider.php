<?php

namespace App\Integrations\Grouvee;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A Grouvee collection, as Grouvee exports it, read in.
 */
final class GrouveeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            if ($this->app->make(MediaTypeRegistry::class)->has(MediaKind::Game->value)) {
                $sources->register(GrouveeCsvSource::CODE, GrouveeCsvSource::class);
            }
        });
    }
}

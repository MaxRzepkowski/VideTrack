<?php

namespace App\Integrations\LibraryThing;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A LibraryThing catalog, as LibraryThing exports it, read in.
 */
final class LibraryThingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            // Books are an optional module.
            if ($this->app->make(MediaTypeRegistry::class)->has(MediaKind::Book->value)) {
                $sources->register(LibraryThingTsvSource::CODE, LibraryThingTsvSource::class);
            }
        });
    }
}

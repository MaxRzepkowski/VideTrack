<?php

namespace App\Integrations\Hardcover;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A reader's Hardcover library, connected once and imported from without a
 * file. Offered only when the operator registered the app it authorizes
 * against, and when books come from Hardcover here, so a Hardcover book id
 * is a ref this instance can open.
 */
final class HardcoverLibraryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            if ($this->configured()) {
                $sources->register(HardcoverLibrarySource::CODE, HardcoverLibrarySource::class);
            }
        });
        $this->callAfterResolving(ConnectionRegistry::class, function (ConnectionRegistry $services): void {
            if ($this->configured()) {
                $services->register(HardcoverService::CODE, HardcoverService::class);
            }
        });
    }

    private function configured(): bool
    {
        $types = $this->app->make(MediaTypeRegistry::class);

        return filled(config('tracker.hardcover.client_id')) && $types->has(MediaKind::Book->value) && $types->get(MediaKind::Book->value)->provider === 'hardcover';
    }
}

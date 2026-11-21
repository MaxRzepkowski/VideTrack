<?php

namespace App\Integrations\WeTrakr;

use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * WeTrakr's export, read in.
 */
final class WeTrakrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            $sources->register(WeTrakrZipSource::CODE, WeTrakrZipSource::class);
        });
    }
}

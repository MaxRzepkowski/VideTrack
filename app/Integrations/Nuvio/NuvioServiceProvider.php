<?php

namespace App\Integrations\Nuvio;

use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Nuvio, through its documented public API: nothing of ours to set up.
 */
final class NuvioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(NuvioSource::CODE, NuvioSource::class));
        $this->callAfterResolving(ConnectionRegistry::class, fn (ConnectionRegistry $services) => $services->register(NuvioService::CODE, NuvioService::class));
    }
}

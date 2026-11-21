<?php

namespace App\Integrations\Simkl;

use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Simkl, connected once through the user's own Simkl app and imported from
 * without a file: nothing to set up on the instance.
 */
final class SimklServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(SimklSource::CODE, SimklSource::class));
        $this->callAfterResolving(ConnectionRegistry::class, fn (ConnectionRegistry $services) => $services->register(SimklService::CODE, SimklService::class));
    }
}

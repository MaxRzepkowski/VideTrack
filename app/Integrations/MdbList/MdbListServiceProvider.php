<?php

namespace App\Integrations\MdbList;

use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * MDBList, connected once with the user's own API key and imported from
 * without a file: nothing to set up on the instance.
 */
final class MdbListServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(MdbListSource::CODE, MdbListSource::class));
        $this->callAfterResolving(ConnectionRegistry::class, fn (ConnectionRegistry $services) => $services->register(MdbListService::CODE, MdbListService::class));
    }
}

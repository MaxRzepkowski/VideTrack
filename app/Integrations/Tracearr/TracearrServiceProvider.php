<?php

namespace App\Integrations\Tracearr;

use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Tracearr, the user's own instance: nothing of ours to set up.
 */
final class TracearrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(TracearrSource::CODE, TracearrSource::class));
        $this->callAfterResolving(ConnectionRegistry::class, fn (ConnectionRegistry $services) => $services->register(TracearrService::CODE, TracearrService::class));
    }
}

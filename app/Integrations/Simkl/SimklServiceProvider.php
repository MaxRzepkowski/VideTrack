<?php

namespace App\Integrations\Simkl;

use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Simkl, connected once and imported from without a file. Offered only when
 * the instance owner registered the app it authorizes against.
 */
final class SimklServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            if ($this->configured()) {
                $sources->register(SimklSource::CODE, SimklSource::class);
            }
        });
        $this->callAfterResolving(ConnectionRegistry::class, function (ConnectionRegistry $services): void {
            if ($this->configured()) {
                $services->register(SimklService::CODE, SimklService::class);
            }
        });
    }

    private function configured(): bool
    {
        return filled(config('tracker.simkl.client_id'));
    }
}

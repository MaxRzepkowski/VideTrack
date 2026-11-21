<?php

namespace App\Integrations\MdbList;

use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * MDBList, connected once and imported from without a file. Offered only
 * when the operator registered the app it authorizes against.
 */
final class MdbListServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            if ($this->configured()) {
                $sources->register(MdbListSource::CODE, MdbListSource::class);
            }
        });
        $this->callAfterResolving(ConnectionRegistry::class, function (ConnectionRegistry $services): void {
            if ($this->configured()) {
                $services->register(MdbListService::CODE, MdbListService::class);
            }
        });
    }

    private function configured(): bool
    {
        return filled(config('tracker.mdblist.client_id'));
    }
}

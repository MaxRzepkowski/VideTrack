<?php

namespace App\Integrations\Kavita;

use App\Catalog\MediaTypeRegistry;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Kavita, the user's own server: nothing of ours to set up. Offered when
 * this instance serves one of the types a Kavita library can be; a run
 * leaves out the libraries of the others.
 */
final class KavitaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            if ($this->servesAKavitaType()) {
                $sources->register(KavitaSource::CODE, KavitaSource::class);
            }
        });
        $this->callAfterResolving(ConnectionRegistry::class, function (ConnectionRegistry $services): void {
            if ($this->servesAKavitaType()) {
                $services->register(KavitaService::CODE, KavitaService::class);
            }
        });
    }

    private function servesAKavitaType(): bool
    {
        $types = $this->app->make(MediaTypeRegistry::class);

        foreach (KavitaLibraryType::cases() as $type) {
            $kind = $type->kind();

            if ($kind !== null && $types->has($kind->value)) {
                return true;
            }
        }

        return false;
    }
}

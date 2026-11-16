<?php

namespace App\Integrations\Netflix;

use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Netflix viewing activity, from the copy of personal data Netflix sends, read in.
 */
final class NetflixServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(NetflixViewingSource::CODE, NetflixViewingSource::class));
    }
}

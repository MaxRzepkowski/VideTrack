<?php

namespace App\Integrations\Trakt;

use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Trakt's ZIP export.
 */
final class TraktServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(TraktZipSource::CODE, TraktZipSource::class));
    }
}

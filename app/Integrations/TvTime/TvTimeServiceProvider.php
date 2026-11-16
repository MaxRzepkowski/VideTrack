<?php

namespace App\Integrations\TvTime;

use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * TV Time's GDPR data package, read in.
 */
final class TvTimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(TvTimeZipSource::CODE, TvTimeZipSource::class));
    }
}

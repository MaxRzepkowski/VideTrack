<?php

namespace App\Integrations\Yamtrack;

use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * A Yamtrack export.
 */
final class YamtrackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(YamtrackSource::CODE, YamtrackSource::class));
    }
}

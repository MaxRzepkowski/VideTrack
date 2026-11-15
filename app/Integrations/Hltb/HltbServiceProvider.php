<?php

namespace App\Integrations\Hltb;

use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * HowLongToBeat's CSV export.
 */
final class HltbServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            // HowLongToBeat names games by title only; the game provider places them.
            if ($this->app->make(MediaTypeRegistry::class)->has('game')) {
                $sources->register(HltbCsvSource::CODE, HltbCsvSource::class);
            }
        });
    }
}

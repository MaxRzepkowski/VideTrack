<?php

namespace App\Integrations\StoryGraph;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * StoryGraph's CSV library export, read in.
 */
final class StoryGraphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            // Books are placed at the book provider; without it the export has nowhere to go.
            if ($this->app->make(MediaTypeRegistry::class)->has(MediaKind::Book->value)) {
                $sources->register(StoryGraphCsvSource::CODE, StoryGraphCsvSource::class);
            }
        });
    }
}

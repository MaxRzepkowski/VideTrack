<?php

namespace App\Integrations\Storyteller;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Storyteller, the user's own server: nothing of ours to set up. Books are
 * placed at the book provider; without it there is nowhere to put them.
 */
final class StorytellerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            if ($this->servesBooks()) {
                $sources->register(StorytellerSource::CODE, StorytellerSource::class);
            }
        });
        $this->callAfterResolving(ConnectionRegistry::class, function (ConnectionRegistry $services): void {
            if ($this->servesBooks()) {
                $services->register(StorytellerService::CODE, StorytellerService::class);
            }
        });
    }

    private function servesBooks(): bool
    {
        return $this->app->make(MediaTypeRegistry::class)->has(MediaKind::Book->value);
    }
}

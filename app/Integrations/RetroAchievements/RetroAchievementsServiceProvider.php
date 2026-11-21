<?php

namespace App\Integrations\RetroAchievements;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * RetroAchievements, read with the player's own key: nothing of the
 * instance's to set up, only games to place them among.
 */
final class RetroAchievementsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            if ($this->app->make(MediaTypeRegistry::class)->has(MediaKind::Game->value)) {
                $sources->register(RetroAchievementsSource::CODE, RetroAchievementsSource::class);
            }
        });
    }
}

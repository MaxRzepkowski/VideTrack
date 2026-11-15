<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\Data\DataSourceCredit;
use App\Catalog\DataSources;
use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProgressUnit;
use App\Catalog\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * IGDB: games, counted in minutes played. Lives behind its credentials:
 * without them the type does not exist on this instance — no dead discover
 * sections, no permanent "provider down" banner.
 */
final class IgdbServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The provider and its parts hold no state: one instance per request or
        // job, rather than a fresh tree of parts on every registry lookup.
        foreach ([IgdbCompanies::class, IgdbDiscovery::class, IgdbGames::class, IgdbPayload::class, IgdbProvider::class] as $class) {
            $this->app->scoped($class);
        }

        $this->callAfterResolving(MediaTypeRegistry::class, function (MediaTypeRegistry $types): void {
            if ($this->configured()) {
                $types->register(new MediaTypeDefinition(
                    code: 'game',
                    provider: 'igdb',
                    playable: true,
                    searchable: true,
                    userStatus: true,
                    progressUnit: ProgressUnit::Minutes,
                    pluralLabel: 'common.games',
                    emoji: '🎮',
                    color: '#10b981',
                ));
            }
        });

        $this->callAfterResolving(ProviderRegistry::class, function (ProviderRegistry $providers): void {
            if ($this->configured()) {
                $providers->register('igdb', IgdbProvider::class);
            }
        });

        $this->callAfterResolving(DataSources::class, function (DataSources $sources): void {
            if ($this->configured()) {
                $sources->credit(new DataSourceCredit('IGDB', 'https://www.igdb.com/', ['app.game_metadata_and_images_are_provided']));
            }
        });
    }

    private function configured(): bool
    {
        return filled(config('tracker.igdb.client_id')) && filled(config('tracker.igdb.client_secret'));
    }
}

<?php

namespace App\Catalog\Providers\Mal;

use App\Catalog\Data\DataSourceCredit;
use App\Catalog\DataSources;
use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProgressUnit;
use App\Catalog\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * MyAnimeList: manga, counted in chapters — MAL rarely knows page counts and
 * readers think in chapters anyway. No client id, no `manga` type.
 */
final class MalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(MediaTypeRegistry::class, function (MediaTypeRegistry $types): void {
            if ($this->configured()) {
                $types->register(new MediaTypeDefinition(
                    code: 'manga',
                    provider: 'mal',
                    playable: true,
                    searchable: true,
                    userStatus: true,
                    progressUnit: ProgressUnit::Chapters,
                    pluralLabel: 'common.manga_plural',
                    emoji: '📖',
                    color: '#d946ef',
                ));
            }
        });

        $this->callAfterResolving(ProviderRegistry::class, function (ProviderRegistry $providers): void {
            if ($this->configured()) {
                $providers->register('mal', MalProvider::class);
            }
        });

        $this->callAfterResolving(DataSources::class, function (DataSources $sources): void {
            if ($this->configured()) {
                $sources->credit(new DataSourceCredit('MyAnimeList', 'https://myanimelist.net/', ['app.manga_metadata_and_covers_are_provided']));
            }
        });
    }

    private function configured(): bool
    {
        return filled(config('tracker.mal.client_id'));
    }
}

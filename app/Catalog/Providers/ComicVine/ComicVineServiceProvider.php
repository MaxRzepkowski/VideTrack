<?php

namespace App\Catalog\Providers\ComicVine;

use App\Catalog\Data\DataSourceCredit;
use App\Catalog\DataSources;
use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProgressUnit;
use App\Catalog\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * ComicVine: comics, a volume tracked flat with an issue counter (a
 * volume→issue hierarchy does not fit ComicVine's request budget). No key, no `comic` type.
 */
final class ComicVineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(MediaTypeRegistry::class, function (MediaTypeRegistry $types): void {
            if ($this->configured()) {
                $types->register(new MediaTypeDefinition(
                    code: 'comic',
                    provider: 'comicvine',
                    playable: true,
                    searchable: true,
                    userStatus: true,
                    progressUnit: ProgressUnit::Issues,
                    pluralLabel: 'common.comics',
                    emoji: '💥',
                    color: '#dc2626',
                ));
            }
        });

        $this->callAfterResolving(ProviderRegistry::class, function (ProviderRegistry $providers): void {
            if ($this->configured()) {
                $providers->register(ComicVineProvider::CODE, ComicVineProvider::class);
            }
        });

        $this->callAfterResolving(DataSources::class, function (DataSources $sources): void {
            if ($this->configured()) {
                $sources->credit(new DataSourceCredit('Comic Vine', 'https://comicvine.gamespot.com/', ['app.comic_metadata_and_images_are_provided']));
            }
        });
    }

    private function configured(): bool
    {
        return filled(config('tracker.comicvine.api_key'));
    }
}

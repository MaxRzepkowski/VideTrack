<?php

namespace App\Integrations\Anime;

use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Integrations\Export\ExportFormatRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Anime and manga lists: MyAnimeList, AniList and Kitsu profiles read in,
 * and MyAnimeList's XML export file both ways.
 */
final class AnimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One index per request or job: an export reads the map from more
        // than one class, and each would otherwise decode the file again.
        $this->app->scoped(AnimeIdMap::class);

        $this->callAfterResolving(ImportSourceRegistry::class, function (ImportSourceRegistry $sources): void {
            // MyAnimeList lists are read with the instance's MAL client id;
            // AniList and Kitsu need no key of ours.
            if (filled(config('tracker.mal.client_id'))) {
                $sources->register(MalListSource::CODE, MalListSource::class);
            }
        });
        // A file needs no key of ours: it names its titles by MAL id itself.
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(MalXmlSource::CODE, MalXmlSource::class));
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(AnilistSource::CODE, AnilistSource::class));
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(KitsuSource::CODE, KitsuSource::class));

        $this->callAfterResolving(ExportFormatRegistry::class, function (ExportFormatRegistry $formats): void {
            $formats->register(MalAnimeExport::CODE, MalAnimeExport::class);

            // Without manga there is nothing to write.
            if ($this->app->make(MediaTypeRegistry::class)->has(MediaKind::Manga->value)) {
                $formats->register(MalMangaExport::CODE, MalMangaExport::class);
            }
        });
    }
}

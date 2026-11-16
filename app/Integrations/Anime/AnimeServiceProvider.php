<?php

namespace App\Integrations\Anime;

use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Anime and manga lists: MyAnimeList, AniList and Kitsu profiles, and
 * MyAnimeList's XML export file.
 */
final class AnimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
    }
}

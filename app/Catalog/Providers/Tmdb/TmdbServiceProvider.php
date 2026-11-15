<?php

namespace App\Catalog\Providers\Tmdb;

use App\Catalog\Data\DataSourceCredit;
use App\Catalog\DataSources;
use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * TMDB: shows with their seasons and episodes, and films; where to watch
 * them comes from JustWatch through TMDB. Always on.
 */
final class TmdbServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The provider and its parts hold no state: one instance per request or
        // job, rather than a fresh tree of parts on every registry lookup.
        foreach ([TmdbCreatorSearch::class, TmdbCreators::class, TmdbCredits::class, TmdbDiscoverFilters::class, TmdbDiscovery::class, TmdbEpisodeLists::class, TmdbEpisodes::class, TmdbLocalizedTitles::class, TmdbPayload::class, TmdbProvider::class, TmdbReleases::class, TmdbTitleParts::class, TmdbTitles::class, TmdbWatchProviders::class] as $class) {
            $this->app->scoped($class);
        }

        $this->callAfterResolving(MediaTypeRegistry::class, function (MediaTypeRegistry $types): void {
            $types->register(new MediaTypeDefinition(
                code: 'show',
                provider: 'tmdb',
                childType: 'season',
                searchable: true,
                specialsChildNumber: 0,
                userStatus: true,
                pluralLabel: 'common.shows',
                emoji: '📺',
                color: '#8136ff',
            ));
            $types->register(new MediaTypeDefinition(
                code: 'movie',
                provider: 'tmdb',
                playable: true,
                searchable: true,
                userStatus: true,
                pluralLabel: 'common.movies',
                emoji: '🎬',
                color: '#2e90fa',
            ));
            $types->register(new MediaTypeDefinition(
                code: 'season',
                provider: 'tmdb',
                parentType: 'show',
                childType: 'episode',
            ));
            $types->register(new MediaTypeDefinition(
                code: 'episode',
                provider: 'tmdb',
                parentType: 'season',
                playable: true,
            ));
        });

        $this->callAfterResolving(ProviderRegistry::class, fn (ProviderRegistry $providers) => $providers->register('tmdb', TmdbProvider::class));
        $this->callAfterResolving(DataSources::class, fn (DataSources $sources) => $sources->credit(
            new DataSourceCredit('The Movie Database (TMDB)', 'https://www.themoviedb.org/', ['app.this_product_uses_the_tmdb_api', 'app.all_metadata_and_images_are_provided'], logo: '/images/tmdb.svg', logoWidth: 273, logoHeight: 36),
            new DataSourceCredit('JustWatch', 'https://www.justwatch.com/', ['app.where_to_watch_data_is_provided'], logo: '/images/justwatch.png', logoWidth: 253, logoHeight: 38),
        ));
    }
}

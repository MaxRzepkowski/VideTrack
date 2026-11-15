<?php

namespace App\Suggestions;

use App\Suggestions\Sources\ActorsRatedWell;
use App\Suggestions\Sources\BestKnownUnseen;
use App\Suggestions\Sources\BiggestByDecade;
use App\Suggestions\Sources\BiggestByYear;
use App\Suggestions\Sources\MissingFromCollections;
use App\Suggestions\Sources\MissingFromDirectors;
use App\Suggestions\Sources\MissingFromWriters;
use App\Suggestions\Sources\RelatedUnseen;
use App\Suggestions\Sources\ReleasedSinceAdded;
use App\Suggestions\Sources\TopRatedUnseen;
use Illuminate\Support\ServiceProvider;

/**
 * The suggestion shelves, in the order their page shows them. A new shelf is
 * a source class and one line here.
 */
final class SuggestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SuggestionSources::class);

        $this->callAfterResolving(SuggestionSources::class, function (SuggestionSources $sources): void {
            $sources->register('released-since-added', ReleasedSinceAdded::class);
            $sources->register('missing-from-collections', MissingFromCollections::class);
            $sources->register('related-unseen', RelatedUnseen::class);
            $sources->register('missing-from-directors', MissingFromDirectors::class);
            $sources->register('missing-from-writers', MissingFromWriters::class);
            $sources->register('actors-rated-well', ActorsRatedWell::class);
            $sources->register('best-known', BestKnownUnseen::class);
            $sources->register('top-rated', TopRatedUnseen::class);
            $sources->register('biggest-by-year', BiggestByYear::class);
            $sources->register('biggest-by-decade', BiggestByDecade::class);
        });
    }
}

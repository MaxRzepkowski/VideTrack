<?php

namespace App\Integrations\ICheckMovies;

use App\Integrations\Import\ImportSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * iCheckMovies checks and lists, as iCheckMovies exports them, read in.
 */
final class ICheckMoviesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(ICheckMoviesCsvSource::CODE, ICheckMoviesCsvSource::class));
    }
}

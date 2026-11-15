<?php

namespace App\Catalog\Providers\Tvmaze;

use App\Catalog\Contracts\SupportsAirSchedule;
use App\Catalog\Data\DataSourceCredit;
use App\Catalog\DataSources;
use Illuminate\Support\ServiceProvider;

/**
 * TVmaze: the exact broadcast time of an episode. Keyless and free, so
 * always on.
 */
final class TvmazeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupportsAirSchedule::class, TvmazeSchedule::class);
        $this->callAfterResolving(DataSources::class, fn (DataSources $sources) => $sources->credit(new DataSourceCredit('TVmaze', 'https://www.tvmaze.com/', ['app.episode_air_times_are_provided_by'], licenseName: 'CC BY-SA', licenseUrl: 'https://creativecommons.org/licenses/by-sa/4.0/')));
    }
}

<?php

namespace App\Catalog\Wikidata;

use App\Catalog\Data\DataSourceCredit;
use App\Catalog\DataSources;
use Illuminate\Support\ServiceProvider;

/**
 * Wikidata: the bridge from a film or an anime to the book or manga it is
 * based on. Keyless, always on.
 */
final class WikidataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(DataSources::class, fn (DataSources $sources) => $sources->credit(new DataSourceCredit('Wikidata', 'https://www.wikidata.org/', ['app.adaptation_data_based_on_comes_from'], licenseName: 'CC0', licenseUrl: 'https://creativecommons.org/publicdomain/zero/1.0/')));
    }
}

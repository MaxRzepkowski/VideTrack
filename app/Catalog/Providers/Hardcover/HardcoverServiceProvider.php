<?php

namespace App\Catalog\Providers\Hardcover;

use App\Catalog\Data\DataSourceCredit;
use App\Catalog\DataSources;
use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProgressUnit;
use App\Catalog\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Hardcover: books, counted in pages. No key, no `book` type.
 */
final class HardcoverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The provider and its parts hold no state: one instance per request or job.
        foreach ([HardcoverAuthors::class, HardcoverBookPage::class, HardcoverEditions::class, HardcoverPayload::class, HardcoverProvider::class, HardcoverSeries::class] as $class) {
            $this->app->scoped($class);
        }

        $this->callAfterResolving(MediaTypeRegistry::class, function (MediaTypeRegistry $types): void {
            if ($this->configured()) {
                $types->register(new MediaTypeDefinition(
                    code: 'book',
                    provider: 'hardcover',
                    playable: true,
                    searchable: true,
                    userStatus: true,
                    progressUnit: ProgressUnit::Pages,
                    pluralLabel: 'common.books',
                    emoji: '📚',
                    color: '#ff6900',
                ));
            }
        });

        $this->callAfterResolving(ProviderRegistry::class, function (ProviderRegistry $providers): void {
            if ($this->configured()) {
                $providers->register(HardcoverProvider::CODE, HardcoverProvider::class);
            }
        });

        $this->callAfterResolving(DataSources::class, function (DataSources $sources): void {
            if ($this->configured()) {
                $sources->credit(new DataSourceCredit('Hardcover', 'https://hardcover.app/', ['app.book_metadata_and_covers_are_provided']));
            }
        });
    }

    private function configured(): bool
    {
        return filled(config('tracker.hardcover.api_key'));
    }
}

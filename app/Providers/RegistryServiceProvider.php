<?php

namespace App\Providers;

use App\Accounts\Identity\IdentityProviderRegistry;
use App\Catalog\DataSources;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Export\ExportFormatRegistry;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\Scrobble\ScrobbleSourceRegistry;
use App\Notifications\Channels\ChannelRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * The registries every module plugs into, created empty. Each media provider
 * and integration fills them from its own service provider, listed in
 * bootstrap/providers.php — that list's order is the order the type filters,
 * the import page and the About page show. Adding a module is a directory
 * and one line there; nothing here changes.
 */
class RegistryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaTypeRegistry::class);
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(ImportSourceRegistry::class);
        $this->app->singleton(ExportFormatRegistry::class);
        $this->app->singleton(ScrobbleSourceRegistry::class);
        $this->app->singleton(ConnectionRegistry::class);
        $this->app->singleton(DataSources::class);
        $this->app->singleton(IdentityProviderRegistry::class);
        $this->app->singleton(ChannelRegistry::class);
    }
}

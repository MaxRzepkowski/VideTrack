<?php

namespace App\Tasks\Maintenance;

use App\Tasks\Attention\AttentionRegistry;
use App\Tasks\Attention\RefusedProviderKeys;
use App\Tasks\Maintenance\Checks\ProviderProbe;
use Illuminate\Support\ServiceProvider;

/**
 * The upkeep of the instance itself: its maintenance checks and what of them
 * needs the admin, beside what its modules register from their own service
 * providers.
 */
final class MaintenanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(MaintenanceRegistry::class, function (MaintenanceRegistry $checks): void {
            $checks->register(ProviderProbe::CODE, ProviderProbe::class);
        });
        $this->callAfterResolving(AttentionRegistry::class, function (AttentionRegistry $attention): void {
            $attention->register('refused-provider-keys', RefusedProviderKeys::class);
        });
    }
}

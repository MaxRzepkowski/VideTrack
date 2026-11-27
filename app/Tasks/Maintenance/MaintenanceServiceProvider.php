<?php

namespace App\Tasks\Maintenance;

use App\Tasks\Attention\AttentionRegistry;
use App\Tasks\Attention\LoopbackAppUrl;
use App\Tasks\Attention\ProblemsFromChecks;
use App\Tasks\Attention\RecentlyFailedJobs;
use App\Tasks\Attention\StalledProcesses;
use App\Tasks\Attention\StorageSetup;
use App\Tasks\Maintenance\Checks\ExpiredDataCleanup;
use App\Tasks\Maintenance\Checks\OrphanSweep;
use App\Tasks\Maintenance\Checks\ProviderProbe;
use Illuminate\Support\ServiceProvider;

/**
 * The upkeep of the instance itself: its maintenance checks and what needs
 * the admin, beside what its modules register from their own service
 * providers.
 */
final class MaintenanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(MaintenanceRegistry::class, function (MaintenanceRegistry $checks): void {
            $checks->register(ProviderProbe::CODE, ProviderProbe::class);
            $checks->register(OrphanSweep::CODE, OrphanSweep::class);
            $checks->register(ExpiredDataCleanup::CODE, ExpiredDataCleanup::class);
        });
        $this->callAfterResolving(AttentionRegistry::class, function (AttentionRegistry $attention): void {
            $attention->register('stalled-processes', StalledProcesses::class);
            $attention->register('problems-from-checks', ProblemsFromChecks::class);
            $attention->register('storage-setup', StorageSetup::class);
            $attention->register('recently-failed-jobs', RecentlyFailedJobs::class);
            $attention->register('app-url', LoopbackAppUrl::class);
        });
    }
}

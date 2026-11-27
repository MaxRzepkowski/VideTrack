<?php

namespace App\Integrations\Apprise;

use App\Notifications\Channels\ChannelRegistry;
use App\Tasks\Maintenance\MaintenanceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Notifications through Apprise, offered only on an instance that runs an
 * apprise-api container and names it.
 */
final class AppriseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The container's description is read once per request or job.
        $this->app->scoped(AppriseServices::class);

        $this->callAfterResolving(ChannelRegistry::class, function (ChannelRegistry $channels): void {
            if (filled(config('tracker.apprise.url'))) {
                $channels->register(AppriseChannel::CODE, AppriseChannel::class);
            }
        });
        $this->callAfterResolving(MaintenanceRegistry::class, fn (MaintenanceRegistry $checks) => $checks->register(AppriseReach::CODE, AppriseReach::class));
    }
}

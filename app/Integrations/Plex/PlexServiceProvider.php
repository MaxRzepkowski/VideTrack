<?php

namespace App\Integrations\Plex;

use App\Integrations\Scrobble\ScrobbleSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Plex's webhooks.
 * Media servers talk to us and need no key of ours, so always on.
 */
final class PlexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ScrobbleSourceRegistry::class, fn (ScrobbleSourceRegistry $sources) => $sources->register(PlexScrobbleSource::CODE, PlexScrobbleSource::class));
    }
}

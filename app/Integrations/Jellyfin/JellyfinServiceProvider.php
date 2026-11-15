<?php

namespace App\Integrations\Jellyfin;

use App\Integrations\Scrobble\ScrobbleSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Jellyfin's webhook plugin.
 * Media servers talk to us and need no key of ours, so always on.
 */
final class JellyfinServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ScrobbleSourceRegistry::class, fn (ScrobbleSourceRegistry $sources) => $sources->register(JellyfinScrobbleSource::CODE, JellyfinScrobbleSource::class));
    }
}

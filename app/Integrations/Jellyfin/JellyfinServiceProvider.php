<?php

namespace App\Integrations\Jellyfin;

use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\Scrobble\ScrobbleSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Jellyfin's webhook plugin, and the backup of its Playback Reporting
 * plugin read in. Neither needs a key of ours, so always on.
 */
final class JellyfinServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ScrobbleSourceRegistry::class, fn (ScrobbleSourceRegistry $sources) => $sources->register(JellyfinScrobbleSource::CODE, JellyfinScrobbleSource::class));
        $this->callAfterResolving(ImportSourceRegistry::class, fn (ImportSourceRegistry $sources) => $sources->register(PlaybackReportingSource::CODE, PlaybackReportingSource::class));
    }
}

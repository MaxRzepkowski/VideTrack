<?php

namespace App\Integrations\Tautulli;

use App\Integrations\Scrobble\ScrobbleSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Tautulli's notification agent.
 * Media servers talk to us and need no key of ours, so always on.
 */
final class TautulliServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ScrobbleSourceRegistry::class, fn (ScrobbleSourceRegistry $sources) => $sources->register(TautulliScrobbleSource::CODE, TautulliScrobbleSource::class));
    }
}

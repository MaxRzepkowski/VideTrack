<?php

namespace App\Integrations\Emby;

use App\Integrations\Scrobble\ScrobbleSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Emby's webhooks.
 * Media servers talk to us and need no key of ours, so always on.
 */
final class EmbyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ScrobbleSourceRegistry::class, fn (ScrobbleSourceRegistry $sources) => $sources->register(EmbyScrobbleSource::CODE, EmbyScrobbleSource::class));
    }
}

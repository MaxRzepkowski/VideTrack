<?php

namespace App\Integrations\Generic;

use App\Integrations\Scrobble\ScrobbleSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * The webhook for the user's own scripts.
 * Media servers talk to us and need no key of ours, so always on.
 */
final class GenericServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ScrobbleSourceRegistry::class, fn (ScrobbleSourceRegistry $sources) => $sources->register(GenericScrobbleSource::CODE, GenericScrobbleSource::class));
    }
}

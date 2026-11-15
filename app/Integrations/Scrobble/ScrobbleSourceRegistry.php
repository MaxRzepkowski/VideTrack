<?php

namespace App\Integrations\Scrobble;

use App\Integrations\Scrobble\Contracts\ScrobbleSource;
use App\Support\ServiceRegistry;

/**
 * The media servers and scripts this instance takes plays from. They need no
 * key of ours — they talk to us — so they are always on the list.
 *
 * @extends ServiceRegistry<ScrobbleSource>
 */
final class ScrobbleSourceRegistry extends ServiceRegistry
{
    protected function kind(): string
    {
        return 'scrobble source';
    }
}

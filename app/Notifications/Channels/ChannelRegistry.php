<?php

namespace App\Notifications\Channels;

use App\Notifications\Channels\Contracts\ChannelDriver;
use App\Notifications\Channels\Data\ChannelKind;
use App\Support\ServiceRegistry;

/**
 * The drivers this instance delivers notifications with. A module
 * registers its driver from its own service provider, and only when the
 * instance can reach its service.
 *
 * @extends ServiceRegistry<ChannelDriver>
 */
final class ChannelRegistry extends ServiceRegistry
{
    /** One kind of a driver, or null when this instance does not offer it. */
    public function kindOf(string $driver, string $kind): ?ChannelKind
    {
        foreach ($this->find($driver)?->kinds() ?? [] as $candidate) {
            if ($candidate->code === $kind) {
                return $candidate;
            }
        }

        return null;
    }

    protected function kind(): string
    {
        return 'notification channel';
    }
}

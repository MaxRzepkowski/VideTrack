<?php

namespace App\Integrations\Discord;

use App\Notifications\Channels\ChannelRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Notifications to a Discord webhook: any instance can post to one.
 */
final class DiscordServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ChannelRegistry::class, function (ChannelRegistry $channels): void {
            $channels->register(DiscordChannel::CODE, DiscordChannel::class);
        });
    }
}

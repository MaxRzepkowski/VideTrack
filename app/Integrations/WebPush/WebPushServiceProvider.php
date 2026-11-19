<?php

namespace App\Integrations\WebPush;

use App\Notifications\Channels\ChannelRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Notifications in the browser and the installed app, offered once the
 * instance has its push key pair (the first start makes one).
 */
final class WebPushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ChannelRegistry::class, function (ChannelRegistry $channels): void {
            if ($this->app->make(VapidKeys::class)->current() !== null) {
                $channels->register(WebPushChannel::CODE, WebPushChannel::class);
            }
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([WebPushKeysCommand::class]);
        }
    }
}

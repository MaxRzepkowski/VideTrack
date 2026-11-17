<?php

namespace App\Integrations\Mail;

use App\Notifications\Channels\ChannelRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * E-mail notifications, offered only where the instance really sends mail:
 * the `log` and `array` mailers only write it down.
 */
final class MailServiceProvider extends ServiceProvider
{
    private const NOT_SENDING = ['log', 'array'];

    public function register(): void
    {
        $this->callAfterResolving(ChannelRegistry::class, function (ChannelRegistry $channels): void {
            if (! in_array(config('mail.default'), self::NOT_SENDING, true)) {
                $channels->register(MailChannel::CODE, MailChannel::class);
            }
        });
    }
}

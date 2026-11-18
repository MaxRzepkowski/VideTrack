<?php

namespace App\Integrations\OutgoingWebhooks;

use App\Events\Media\MediaEventDetected;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Webhooks hear the library's changes and the bell's notifications from
 * here: the module listens, nothing in the library knows it is there.
 */
final class OutgoingWebhooksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(array_keys(WebhookMessages::TYPES), [AnnounceToWebhooks::class, 'handleChange']);
        Event::listen(MediaEventDetected::class, [AnnounceToWebhooks::class, 'handleNotification']);
    }
}

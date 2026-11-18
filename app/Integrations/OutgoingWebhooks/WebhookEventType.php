<?php

namespace App\Integrations\OutgoingWebhooks;

/** What a webhook message is about: the `type` a receiver branches on, a public contract. */
enum WebhookEventType: string
{
    /** The settings page's test. */
    case Test = 'webhook.test';
}

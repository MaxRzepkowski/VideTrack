<?php

namespace App\Integrations\OutgoingWebhooks\Data;

use App\Integrations\OutgoingWebhooks\WebhookEventGroup;

/** What the user sent about a webhook; a null field was not sent. */
final readonly class OutgoingWebhookSettings
{
    /**
     * @param  string|null  $label  empty names the webhook after its address's host
     * @param  string|null  $secret  null or empty keeps the saved one
     * @param  list<WebhookEventGroup>|null  $events
     */
    public function __construct(
        public ?string $label,
        public ?string $url,
        public ?string $secret,
        public ?array $events,
        public ?bool $enabled,
    ) {}
}

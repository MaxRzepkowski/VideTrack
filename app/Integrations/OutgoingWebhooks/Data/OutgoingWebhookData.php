<?php

namespace App\Integrations\OutgoingWebhooks\Data;

use App\Integrations\OutgoingWebhooks\WebhookEventGroup;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One of the user's webhooks as the settings page lists it: never its address or its secret. */
#[TypeScript]
final readonly class OutgoingWebhookData
{
    /**
     * @param  list<WebhookEventGroup>  $events
     */
    public function __construct(
        public int $id,
        public string $label,
        /** Where it posts, as far as the page tells it: the host alone, a path may hold a token. */
        public string $host,
        public array $events,
        public bool $enabled,
        /** How the last message failed, worded on this side; null once one arrived. */
        public ?string $lastFailure,
        /** A fresh signing secret its Generate button puts in, its own: no two forms share one. */
        public string $newSecret,
    ) {}
}

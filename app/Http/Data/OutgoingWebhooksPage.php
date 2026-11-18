<?php

namespace App\Http\Data;

use App\Integrations\OutgoingWebhooks\Data\OutgoingWebhookData;
use App\Integrations\OutgoingWebhooks\Data\WebhookGroupOption;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The webhooks page: the user's webhooks and what the form offers. */
#[TypeScript]
final readonly class OutgoingWebhooksPage
{
    /**
     * @param  list<OutgoingWebhookData>  $webhooks
     * @param  list<WebhookGroupOption>  $groups
     */
    public function __construct(
        public array $webhooks,
        public array $groups,
        /** A fresh signing secret the new webhook's Generate button puts in. */
        public string $newSecret,
        public int $labelMax,
        public int $urlMax,
        /** How many webhooks an account may keep. */
        public int $limit,
    ) {}
}

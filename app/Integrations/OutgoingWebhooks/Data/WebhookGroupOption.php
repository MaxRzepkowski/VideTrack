<?php

namespace App\Integrations\OutgoingWebhooks\Data;

use App\Integrations\OutgoingWebhooks\WebhookEventGroup;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A group of events as the form offers it, worded in the reader's language. */
#[TypeScript]
final readonly class WebhookGroupOption
{
    public function __construct(
        public WebhookEventGroup $code,
        public string $label,
        public string $description,
    ) {}

    public static function of(WebhookEventGroup $group): self
    {
        return new self($group, $group->label(), $group->description());
    }
}

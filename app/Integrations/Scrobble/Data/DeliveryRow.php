<?php

namespace App\Integrations\Scrobble\Data;

use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Scrobble\DeliveryNote;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One delivery in an endpoint's history on the integrations page.
 */
#[TypeScript]
final readonly class DeliveryRow
{
    public function __construct(
        public int $id,
        public WebhookDeliveryStatus $status,
        public ?string $kind,
        public ?string $label,
        /** Why it ended as it did, worded on the page; null when it needs no word. */
        public ?DeliveryNote $message,
        public ?string $at,
        /** What the server actually sent, pretty-printed, kept for the payloads that could not be read. */
        public ?string $payload,
    ) {}
}

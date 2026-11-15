<?php

namespace App\Integrations\Scrobble\Data;

use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Scrobble\DeliveryNote;

/** What a scrobble event came to: saved to the library, or one of the nothings, named. */
final readonly class ScrobbleOutcome
{
    private function __construct(
        public WebhookDeliveryStatus $status,
        public ?DeliveryNote $note,
    ) {}

    public static function accepted(): self
    {
        return new self(WebhookDeliveryStatus::Accepted, null);
    }

    public static function ignored(DeliveryNote $note): self
    {
        return new self(WebhookDeliveryStatus::Ignored, $note);
    }
}

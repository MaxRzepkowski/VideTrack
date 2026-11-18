<?php

namespace App\Integrations\OutgoingWebhooks;

use JsonException;

/**
 * One message to a webhook as the receiver reads it: what happened
 * (`type`), when, and its data in the shapes the API gives them. The id is
 * the message's for good: a retry carries the same one, so a receiver can
 * tell a repeat from a new event.
 */
final readonly class WebhookMessage
{
    /**
     * @param  string  $timestamp  when it happened (ISO 8601), not when a retry delivered it
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public WebhookEventType $type,
        public string $timestamp,
        public array $data,
    ) {}

    /**
     * The body exactly as it is signed and sent.
     *
     * @throws JsonException
     */
    public function body(): string
    {
        return json_encode([
            'type' => $this->type->value,
            'timestamp' => $this->timestamp,
            'data' => $this->data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

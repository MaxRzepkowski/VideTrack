<?php

namespace App\Integrations\Scrobble\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One webhook address a user gave a media server, with its latest history.
 */
#[TypeScript]
final readonly class WebhookEndpointData
{
    /**
     * @param  list<DeliveryRow>  $deliveries  newest first
     */
    public function __construct(
        public int $id,
        public ?string $label,
        public bool $enabled,
        public ?string $serverUsername,
        public string $url,
        /** The address names this machine only: useless to a server elsewhere. */
        public bool $loopback,
        public ?string $lastReceivedAt,
        /** Worded on this side: the job stores a translation key, never exception text. */
        public ?string $lastError,
        public array $deliveries,
    ) {}
}

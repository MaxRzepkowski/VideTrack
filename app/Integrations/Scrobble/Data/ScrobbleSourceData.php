<?php

namespace App\Integrations\Scrobble\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A media server's card on the integrations page: how to set it up, and the
 * addresses the user gave it.
 */
#[TypeScript]
final readonly class ScrobbleSourceData
{
    /**
     * @param  list<SetupStep>  $instructions
     * @param  list<WebhookEndpointData>  $endpoints
     */
    public function __construct(
        public string $code,
        /** The product's own name: never translated. */
        public string $name,
        public string $description,
        public ?string $notice,
        public array $instructions,
        public bool $canTest,
        /** How many addresses one account may have for this server. */
        public int $endpointLimit,
        public array $endpoints,
    ) {}
}

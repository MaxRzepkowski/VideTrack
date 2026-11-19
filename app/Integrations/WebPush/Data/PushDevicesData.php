<?php

namespace App\Integrations\WebPush\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What the settings page needs to switch push on in a browser, and the browsers it is on. */
#[TypeScript]
final readonly class PushDevicesData
{
    /**
     * @param  list<PushDeviceData>  $devices
     */
    public function __construct(
        /** The instance's key a browser subscribes with. */
        public string $publicKey,
        public array $devices,
    ) {}
}

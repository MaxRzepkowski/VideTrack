<?php

namespace App\Integrations\WebPush\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One browser the user's notifications are pushed to, as the settings page lists it. */
#[TypeScript]
final readonly class PushDeviceData
{
    public function __construct(
        public int $id,
        /** The browser's own description, which the page names it by. */
        public ?string $userAgent,
        /** The hash of its address: the page finds the browser it is open in by it, the address never leaves. */
        public string $fingerprint,
        public string $subscribedAt,
        /** Subscribed with a key pair the instance no longer has: it hears nothing until switched on again. */
        public bool $stale,
    ) {}
}

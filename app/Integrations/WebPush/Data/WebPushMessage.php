<?php

namespace App\Integrations\WebPush\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What the service worker receives in a push and shows as a notification. */
#[TypeScript]
final readonly class WebPushMessage
{
    public function __construct(
        public string $title,
        public ?string $body,
        /** The instance's page a click opens. */
        public string $url,
        public string $icon,
        /** When the notification was written, in milliseconds since the epoch. */
        public int $timestamp,
    ) {}
}

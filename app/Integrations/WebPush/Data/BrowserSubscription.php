<?php

namespace App\Integrations\WebPush\Data;

use Minishlink\WebPush\ContentEncoding;

/** What a browser hands over when it subscribes to push: where to send, and the keys to encrypt to. */
final readonly class BrowserSubscription
{
    public function __construct(
        public string $endpoint,
        public string $publicKey,
        public string $authToken,
        public ContentEncoding $contentEncoding,
        public ?string $userAgent,
    ) {}
}

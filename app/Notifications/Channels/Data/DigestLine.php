<?php

namespace App\Notifications\Channels\Data;

/**
 * One notification in a digest: the title it is about (leading to its page),
 * what happened, and the facts after it. NotificationCopy::digestParts()
 * words the three.
 */
final readonly class DigestLine
{
    public function __construct(
        public string $subject,
        public string $url,
        public string $label,
        public ?string $meta,
    ) {}
}

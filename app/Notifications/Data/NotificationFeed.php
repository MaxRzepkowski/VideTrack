<?php

namespace App\Notifications\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the header bell reads: the shared prop and the feed it polls alike.
 */
#[TypeScript]
final readonly class NotificationFeed
{
    /**
     * @param  list<NotificationEntry>  $recent  the latest few, newest first
     */
    public function __construct(
        public int $unread,
        public array $recent,
    ) {}
}

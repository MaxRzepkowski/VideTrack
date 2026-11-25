<?php

namespace App\Notifications\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the header bell reads when it opens, and polls while the tab is open.
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

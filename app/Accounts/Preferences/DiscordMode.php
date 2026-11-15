<?php

namespace App\Accounts\Preferences;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How Discord messages are delivered.
 */
#[TypeScript]
enum DiscordMode: string
{
    /** One message a day at a fixed local hour: a webhook should summarize, not buzz. */
    case Digest = 'digest';

    /** A ping the moment each notification is created. */
    case Instant = 'instant';
}

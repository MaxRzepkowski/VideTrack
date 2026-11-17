<?php

namespace App\Notifications\Channels;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** When a channel hears about a notification. */
#[TypeScript]
enum ChannelMode: string
{
    /** One message a day at a fixed local hour: a channel should summarize, not buzz. */
    case Digest = 'digest';

    /** A message the moment each notification is created. */
    case Instant = 'instant';

    /** What a new channel does unless told otherwise: a summary does not buzz. */
    public static function default(): self
    {
        return self::Digest;
    }
}

<?php

namespace App\Integrations\Discord;

/** How Discord took a message posted to a webhook. */
enum DiscordAnswer
{
    case Delivered;

    /** The webhook is gone: deleted in Discord, or its address mistyped. */
    case Gone;

    /** Discord answered and refused (a malformed message). */
    case Refused;

    /** Discord took too many at once from this webhook; a moment later it takes them. */
    case Throttled;

    /** Discord could not be reached or failed itself; worth another try later. */
    case Unavailable;

    public static function ofStatus(int $status): self
    {
        return match (true) {
            $status >= 200 && $status < 300 => self::Delivered,
            $status === 401 || $status === 404 => self::Gone,
            $status === 429 => self::Throttled,
            $status >= 500 => self::Unavailable,
            default => self::Refused,
        };
    }
}

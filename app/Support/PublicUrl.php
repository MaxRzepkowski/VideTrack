<?php

namespace App\Support;

/**
 * The instance's address for a link that reaches someone else: a reset
 * e-mail, a Discord message. Such a link comes from `APP_URL`, never from the
 * request that caused it, whose `Host` and `X-Forwarded-Host` the sender
 * chooses. Pages keep the host the visitor came by.
 */
final class PublicUrl
{
    /** @param  string  $path  a path from `route(…, absolute: false)` */
    public static function to(string $path): string
    {
        return rtrim(config()->string('app.url'), '/').'/'.ltrim($path, '/');
    }
}

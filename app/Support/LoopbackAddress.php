<?php

namespace App\Support;

/**
 * An address that names the computer it is read on (`tracker.loopback_hosts`):
 * from anywhere else it leads nowhere — a webhook a media server in its own
 * container sends to it, a link in a notification opened on a phone.
 */
final class LoopbackAddress
{
    public static function is(string $url): bool
    {
        $host = trim(mb_strtolower((string) parse_url($url, PHP_URL_HOST)), '[]');

        return in_array($host, config()->array('tracker.loopback_hosts'), true);
    }
}

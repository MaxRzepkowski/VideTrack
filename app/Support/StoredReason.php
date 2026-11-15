<?php

namespace App\Support;

/**
 * A reason a background run kept for later (an endpoint's or a connection's
 * last error): a translation key, or text an outside service already worded.
 * Worded in the reader's language where it is a key, shown as kept otherwise.
 */
final class StoredReason
{
    public static function worded(?string $stored): ?string
    {
        if ($stored === null) {
            return null;
        }

        $worded = __($stored);

        return is_string($worded) ? $worded : $stored;
    }
}

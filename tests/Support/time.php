<?php

/**
 * Runs the rest of the test on an instance that keeps time in another zone:
 * the configured zone and PHP's default, which Eloquent reads stored times
 * in. The next test boots a fresh application, which sets both back.
 */
function instanceTimezone(string $zone): void
{
    config(['app.timezone' => $zone]);
    date_default_timezone_set($zone);
}

/**
 * Runs `$body` with PHP's default zone, the clock stored times are read on,
 * set to `$zone`, and sets the previous one back after: for a test with no
 * application to boot a fresh one.
 */
function onClock(string $zone, Closure $body): void
{
    $previous = date_default_timezone_get();
    date_default_timezone_set($zone);

    try {
        $body();
    } finally {
        date_default_timezone_set($previous);
    }
}

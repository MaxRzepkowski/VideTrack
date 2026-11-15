<?php

namespace App\Catalog;

use Throwable;

/**
 * The provider asked to be waited out, or our own budget for it is spent:
 * unavailable for now, with the seconds after which asking again is welcome.
 * Still a kind of unavailability, so every catch of the parent holds; only a
 * caller that can wait (the background refresh) reads the delay.
 */
final class ProviderBusy extends ProviderException
{
    /** Waited when the service names no time of its own. */
    public const DEFAULT_RETRY_AFTER_SECONDS = 60;

    /**
     * @param  array<string, int|string>  $replace
     */
    public function __construct(string $key, public readonly int $retryAfterSeconds, array $replace = [], ?Throwable $previous = null)
    {
        parent::__construct($key, $replace, $previous);
    }
}

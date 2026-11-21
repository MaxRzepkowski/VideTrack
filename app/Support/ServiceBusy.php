<?php

namespace App\Support;

use Throwable;

/**
 * The service asked to be left alone for a while (a 429 with its
 * Retry-After): still unavailable this time, but with how long to wait.
 */
final class ServiceBusy extends ServiceUnavailable
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public function __construct(string $key, public readonly int $retryAfterSeconds, array $replace = [], ?Throwable $previous = null)
    {
        parent::__construct($key, $replace, $previous);
    }
}

<?php

namespace App\Catalog;

use App\Support\ServiceUnavailable;

/**
 * Metadata provider failure (network, upstream error, invalid response).
 */
class ProviderException extends ServiceUnavailable
{
    /** An entry the service always names by id came without one: not an answer we can read. */
    public static function missingId(string $service): self
    {
        return new self("{$service} answered an entry without an id.");
    }
}

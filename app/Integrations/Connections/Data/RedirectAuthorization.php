<?php

namespace App\Integrations\Connections\Data;

use InvalidArgumentException;

/**
 * The start of a trip to the service: the address to send the user to, and
 * what the instance keeps until they are back (credentials, so it stays on
 * the server).
 */
final readonly class RedirectAuthorization
{
    /**
     * @param  array<string, string>  $kept
     */
    public function __construct(
        public string $url,
        public array $kept,
    ) {
        // The page sends the browser wherever this says: only ever to a site.
        if (! str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException('A trip to a service goes to an https address.');
        }
    }
}

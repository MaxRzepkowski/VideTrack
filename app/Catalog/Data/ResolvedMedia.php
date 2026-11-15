<?php

namespace App\Catalog\Data;

/**
 * Our own coordinates for something that arrived under somebody else's id.
 */
final readonly class ResolvedMedia
{
    public function __construct(
        public string $type,
        public string $ref,
    ) {}
}

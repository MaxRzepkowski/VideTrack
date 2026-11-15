<?php

namespace App\Catalog\Data;

/**
 * A title page's details and where they came from. Served from the library
 * when the provider failed; every page in that mode says so (an incomplete
 * page that does not say why is worse than an error).
 */
final readonly class TitleDetails
{
    public function __construct(
        public MediaDetails $details,
        public bool $fromLibrary = false,
        /** The provider answered "not found": it dropped the title, it is not down. */
        public bool $removedAtSource = false,
    ) {}
}

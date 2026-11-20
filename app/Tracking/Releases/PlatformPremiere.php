<?php

namespace App\Tracking\Releases;

use App\Catalog\DatePrecision;

/**
 * One day a title premieres on as one viewer sees it: the platforms it comes
 * to that day as the same kind of release, whatever region each date was
 * picked from.
 */
final readonly class PlatformPremiere
{
    /**
     * @param  list<string>  $platforms
     */
    public function __construct(
        public string $date,
        public DatePrecision $precision,
        /** The kind of release (Early Access, a next-gen patch…); null for a plain one. */
        public ?string $status,
        public array $platforms,
    ) {}

    /** The card's one platform field, naming them all; null when the provider named none. */
    public function cardPlatform(): ?string
    {
        return $this->platforms === [] ? null : implode(', ', $this->platforms);
    }
}

<?php

namespace App\Catalog\Data;

use Carbon\CarbonImmutable;

/** One event as its provider lists it, with the titles it showed. */
final readonly class EventData
{
    /**
     * @param  list<MediaSummary>  $titles  as the provider lists them
     */
    public function __construct(
        public string $ref,
        public string $name,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt = null,
        public ?string $description = null,
        /** The zone the provider times it in (America/Los_Angeles). */
        public ?string $timeZone = null,
        public ?string $liveStreamUrl = null,
        public ?string $logoPath = null,
        public array $titles = [],
    ) {}
}

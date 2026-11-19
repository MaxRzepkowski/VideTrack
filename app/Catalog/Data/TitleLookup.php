<?php

namespace App\Catalog\Data;

use App\Catalog\MediaKind;

/** One title asked for by name, and what the catalogue has under it. */
final readonly class TitleLookup
{
    public function __construct(
        public MediaKind $kind,
        public string $title,
        public ?int $year,
        /** Null when nothing goes by that name in that year. */
        public ?MediaSummary $match,
        /** The catalogue could not be asked: unknown, not missing. */
        public bool $unavailable = false,
    ) {}
}

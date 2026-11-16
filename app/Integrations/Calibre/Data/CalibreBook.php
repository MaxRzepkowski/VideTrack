<?php

namespace App\Integrations\Calibre\Data;

use Carbon\CarbonImmutable;

/**
 * One book of a Calibre library, as much of it as the import reads.
 * $rating is Calibre's own: 1–10, two points a star.
 */
final readonly class CalibreBook
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $author,
        public ?string $isbn,
        public ?string $goodreads,
        public ?int $rating,
        public ?CarbonImmutable $added,
        public ?int $year,
    ) {}
}

<?php

namespace App\Integrations\KOReader\Data;

use Carbon\CarbonImmutable;

/**
 * One book of KOReader's reading statistics: who wrote it, and when its
 * pages were read. KOReader keeps no "finished" mark there, so a book
 * reached its end when a page in its last stretch was read.
 */
final readonly class KOReaderBook
{
    public function __construct(
        public string $title,
        public ?string $author,
        /** When the first page was read; null for a book only opened. */
        public ?CarbonImmutable $started,
        /** When a page at the end was read, the last time; null for one not read to the end. */
        public ?CarbonImmutable $finished,
    ) {}
}

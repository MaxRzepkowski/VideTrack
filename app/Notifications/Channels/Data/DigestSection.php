<?php

namespace App\Notifications\Channels\Data;

/** One kind of title in a digest: a root media type, or the catch-all. */
final readonly class DigestSection
{
    /**
     * @param  non-empty-list<DigestLine>  $lines
     */
    public function __construct(
        public string $heading,
        public ?string $emoji,
        public array $lines,
    ) {}
}

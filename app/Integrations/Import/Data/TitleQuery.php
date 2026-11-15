<?php

namespace App\Integrations\Import\Data;

/**
 * The last resort for a title without a usable id: search the type's provider
 * by name. Every hint narrows the match — a year within one of the result's,
 * a creator among its authors, the very same title — and an unmatched hint
 * means no match rather than a guess. A source with no hint but the title
 * (HowLongToBeat) asks for the exact title, or the first search hit would be
 * whatever the provider ranks highest.
 */
final readonly class TitleQuery
{
    public function __construct(
        public string $title,
        public ?int $year = null,
        public ?string $creator = null,
        public bool $exactTitle = false,
    ) {}
}

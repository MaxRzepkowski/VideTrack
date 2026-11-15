<?php

namespace App\Catalog\Data;

/**
 * One row of a title's subtree as the walk reads it: light enough for the
 * thousands of episodes of a long show, typed for the modules that read it.
 */
final readonly class SubtreeNode
{
    public function __construct(
        public int $id,
        public ?int $parentId,
        public string $type,
        public ?int $number,
        /** The rule of MediaItem::hasAired(). */
        public bool $aired,
        /** Whether a cascade from the walk's root reaches the row (specials branches do not). */
        public bool $inCascade,
    ) {}
}

<?php

namespace App\Library\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A list's card on the lists pages.
 */
#[TypeScript]
final readonly class ListOverview
{
    /**
     * @param  list<array{path: string, logo: bool}>  $posterPaths  the collage: a company logo renders contained on white, the rest cover-cropped
     */
    public function __construct(
        public int $id,
        /** As written; null for the backlog, which has no title of its own. */
        public ?string $title,
        public ?string $systemKey,
        /** The title as shown: the backlog's in the reader's language, a user list's as written. */
        public string $displayTitle,
        public bool $isPublic,
        public int $itemCount,
        /** The index sorts by it on the page; the manual order is the order the cards come in. */
        public ?string $createdAt,
        public array $posterPaths,
    ) {}
}

<?php

namespace App\Library\Data;

use App\Catalog\Data\MediaSummary;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One position on a list's page: a title, or a person or a company carried as
 * a card of their own type.
 */
#[TypeScript]
final readonly class ListItemEntry
{
    /**
     * @param  list<string>  $genres  the genres of the position's root, for the page's genre filter; none for people and companies
     */
    public function __construct(
        public int $id,
        public ?string $addedAt,
        public array $genres,
        /** The title's own row, for the spreadsheet export; null for people and companies. */
        public ?int $mediaItemId,
        /** What the export and the rating services key a title by. */
        public ?string $imdbId,
        public MediaSummary $media,
    ) {}
}

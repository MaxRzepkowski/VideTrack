<?php

namespace App\Tracking\Data;

use App\Catalog\Data\MediaSummary;
use App\Tracking\Releases\ReleaseEntry;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One countdown card: a calendar entry and the root type the page's type
 * filter files it under (an episode under its show).
 */
#[TypeScript]
final readonly class CountdownEntry
{
    public function __construct(
        public MediaSummary $media,
        /** The show's title, for an episode. */
        public ?string $showTitle,
        public string $filterType,
    ) {}

    public static function of(ReleaseEntry $entry): self
    {
        return new self($entry->media, $entry->showTitle, $entry->rootType);
    }
}

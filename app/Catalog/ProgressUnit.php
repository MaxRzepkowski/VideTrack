<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The unit a playable type counts progress in: time for watch/play types,
 * a position counter for reading types. A backed enum so it serializes to the
 * same string the client already expects (`json_encode` yields the value).
 */
#[TypeScript]
enum ProgressUnit: string
{
    case Minutes = 'minutes';
    case Pages = 'pages';
    case Chapters = 'chapters';
    case Issues = 'issues';

    /** A position counter of something read (pages, chapters, issues), not time: its time can only be estimated. */
    public function isRead(): bool
    {
        return $this !== self::Minutes;
    }
}

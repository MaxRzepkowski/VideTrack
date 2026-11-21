<?php

namespace App\Integrations\MdbList;

use App\Catalog\MediaKind;

/** What a list item is, by its `mediatype`. */
enum MdbListMediaType: string
{
    case Movie = 'movie';
    case Show = 'show';

    public function kind(): MediaKind
    {
        return match ($this) {
            self::Movie => MediaKind::Movie,
            self::Show => MediaKind::Show,
        };
    }
}

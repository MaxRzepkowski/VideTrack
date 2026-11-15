<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Where a title's video is hosted: the two hosts the page knows how to play. */
#[TypeScript]
enum VideoSite: string
{
    case YouTube = 'YouTube';
    case Vimeo = 'Vimeo';
}

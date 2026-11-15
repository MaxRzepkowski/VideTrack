<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The kinds of video a title page offers, in the order it offers them. The
 * values are TMDB's; any other kind it sends (opening credits, recaps) is
 * left out.
 */
#[TypeScript]
enum VideoKind: string
{
    case Trailer = 'Trailer';
    case Teaser = 'Teaser';
    case Clip = 'Clip';
    case Featurette = 'Featurette';
    case BehindTheScenes = 'Behind the Scenes';
    case Bloopers = 'Bloopers';
}

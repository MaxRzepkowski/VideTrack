<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The forms a film opens in, one country at a time: a festival premiere, a
 * limited run, wide distribution, then digital, disc and television.
 */
#[TypeScript]
enum FilmReleaseType: string
{
    case Premiere = 'premiere';
    case Limited = 'limited';
    case Theatrical = 'theatrical';
    case Digital = 'digital';
    case Disc = 'disc';
    case Tv = 'tv';
}

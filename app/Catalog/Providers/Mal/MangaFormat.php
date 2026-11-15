<?php

namespace App\Catalog\Providers\Mal;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The formats MAL mixes in one catalogue, as its `media_type` names them; the
 * format chip tells them apart. Prose (novels) is not among them.
 */
#[TypeScript]
enum MangaFormat: string
{
    case Manga = 'manga';
    case Manhwa = 'manhwa';
    case Manhua = 'manhua';
    case OneShot = 'one_shot';
    case Doujinshi = 'doujinshi';
    case Oel = 'oel';
}

<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What kind of book a card stands for, when it is not a book of its own
 * length and form: the badge its card wears and the chip its page shows. A
 * provider maps its own codes onto these.
 */
#[TypeScript]
enum BookKind: string
{
    case Novella = 'novella';
    case ShortStory = 'short_story';
    case GraphicNovel = 'graphic_novel';
    case FanFiction = 'fan_fiction';
    case Research = 'research';
    case Poetry = 'poetry';
    case Anthology = 'anthology';
    case WebNovel = 'web_novel';
    case LightNovel = 'light_novel';
}

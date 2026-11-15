<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The part a person had in a title. TMDB's crew departments, plus the
 * credits other sources give: a guest star on one episode, a book's author,
 * its translator and the narrator of its audiobook.
 * Providers map their own names onto these codes.
 */
#[TypeScript]
enum Department: string
{
    case Acting = 'acting';
    case GuestStar = 'guest_star';
    case Directing = 'directing';
    case Writing = 'writing';
    case Author = 'author';
    case Translation = 'translation';
    case Narration = 'narration';
    case Production = 'production';
    case Camera = 'camera';
    case Editing = 'editing';
    case Sound = 'sound';
    case Art = 'art';
    case CostumeMakeUp = 'costume_make_up';
    case VisualEffects = 'visual_effects';
    case Lighting = 'lighting';
    case Crew = 'crew';
}

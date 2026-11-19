<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The sort axes of the activity panels (history, ratings, favorites). */
#[TypeScript]
enum ActivitySort: string
{
    /** When the entry happened: a play's date, a rating's last edit. */
    case Activity = 'activity';
    /** When the entry was first made — the one date a favorite has. */
    case Added = 'added';
    case MyRating = 'my_rating';
    case Community = 'community';
    case Title = 'title';
    case Release = 'release';
    case Runtime = 'runtime';
    /** How many times a position was finished — a film's or an episode's completions, a show's or a season's full runs. */
    case Completions = 'completions';

    /** Each axis reads in its natural direction: A→Z, everything else biggest first. */
    public function naturalDirection(): SortDirection
    {
        return $this === self::Title ? SortDirection::Asc : SortDirection::Desc;
    }
}

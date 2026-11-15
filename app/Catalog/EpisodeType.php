<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the provider says an episode is within its season: an ordinary one,
 * the season's finale or a mid-season finale. Unknown when the provider says
 * nothing (or the row carries none): the season's last known episode is
 * then taken for its finale.
 */
#[TypeScript]
enum EpisodeType: string
{
    case Standard = 'standard';
    case Finale = 'finale';
    case MidSeason = 'mid_season';
}

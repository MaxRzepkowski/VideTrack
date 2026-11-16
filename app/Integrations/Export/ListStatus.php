<?php

namespace App\Integrations\Export;

/**
 * The five states a title stands in on any list-keeping service, under
 * whatever names it gives them (MAL "plan_to_watch", AniList "PLANNING",
 * Kitsu "planned", Goodreads "to-read"…).
 */
enum ListStatus: string
{
    case Current = 'current';
    case Completed = 'completed';
    case OnHold = 'on_hold';
    case Dropped = 'dropped';
    case Planned = 'planned';
}

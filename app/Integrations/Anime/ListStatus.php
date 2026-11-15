<?php

namespace App\Integrations\Anime;

/**
 * The five states every anime/manga tracker shares, under whatever names
 * (MAL "plan_to_watch", AniList "PLANNING", Kitsu "planned"…).
 */
enum ListStatus: string
{
    case Current = 'current';
    case Completed = 'completed';
    case OnHold = 'on_hold';
    case Dropped = 'dropped';
    case Planned = 'planned';
}

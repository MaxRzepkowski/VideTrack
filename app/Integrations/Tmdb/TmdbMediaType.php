<?php

namespace App\Integrations\Tmdb;

/** The kind of an item on a TMDB list (`media_type`). */
enum TmdbMediaType: string
{
    case Movie = 'movie';
    case Tv = 'tv';
}

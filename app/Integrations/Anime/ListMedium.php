<?php

namespace App\Integrations\Anime;

/**
 * Which of a tracker's two lists an entry comes from. The values are the
 * words MyAnimeList, AniList and Kitsu all use for them.
 */
enum ListMedium: string
{
    case Anime = 'anime';
    case Manga = 'manga';
}

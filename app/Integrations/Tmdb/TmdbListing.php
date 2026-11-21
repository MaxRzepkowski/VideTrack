<?php

namespace App\Integrations\Tmdb;

use App\Catalog\MediaKind;
use LogicException;

/** What an account listing of TMDB holds, by the first part of its path. */
enum TmdbListing: string
{
    case Watchlist = 'watchlist';
    case Favorites = 'favorite';
    case Rated = 'rated';

    /** The listing's path below the account, for a kind of title: `watchlist/movies`, `rated/tv`. */
    public function path(MediaKind $kind): string
    {
        return $this->value.'/'.match ($kind) {
            MediaKind::Movie => 'movies',
            MediaKind::Show => 'tv',
            MediaKind::Episode => 'tv/episodes',
            default => throw new LogicException("A TMDB account lists no {$kind->value}."),
        };
    }
}

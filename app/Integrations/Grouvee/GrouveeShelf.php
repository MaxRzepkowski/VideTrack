<?php

namespace App\Integrations\Grouvee;

/**
 * What a Grouvee game stands for here, read from its shelves and its
 * rating. A game can sit on several shelves at once.
 */
enum GrouveeShelf: string
{
    case Played = 'played';
    case Playing = 'playing';
    case Wanted = 'wanted';
    case Rated = 'rated';

    /** Grouvee's own shelves, folded, by what they make of a game; any other is the user's. */
    public const SHELVES = [
        'played' => self::Played,
        'playing' => self::Playing,
        'backlog' => self::Wanted,
        'wish list' => self::Wanted,
    ];

    /** The translation key of its category in an import's selection. */
    public function importLabel(): string
    {
        return match ($this) {
            self::Played => 'import.games_played',
            self::Playing => 'import.games_playing',
            self::Wanted => 'import.watchlist',
            self::Rated => 'common.ratings',
        };
    }
}

<?php

namespace App\Integrations\AnimePlanet;

/**
 * The status an Anime-Planet manga entry carries. "Won't read" is a status
 * of its own there and nothing here: those entries stay behind.
 */
enum AnimePlanetStatus: string
{
    case Read = 'read';
    case Reading = 'reading';
    case Stalled = 'stalled';
    case WantToRead = 'want to read';
    case Dropped = 'dropped';

    /** The translation key of its category in an import's selection. */
    public function importLabel(): string
    {
        return match ($this) {
            self::Read => 'import.shelf_read',
            self::Reading => 'import.shelf_currently_reading',
            self::Stalled => 'import.shelf_paused',
            self::WantToRead => 'import.watchlist',
            self::Dropped => 'import.shelf_did_not_finish',
        };
    }
}

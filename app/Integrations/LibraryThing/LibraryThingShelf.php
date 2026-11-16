<?php

namespace App\Integrations\LibraryThing;

use App\Integrations\Import\LibraryList;

/**
 * What a LibraryThing book stands for here, read from its collections, its
 * reading date and its rating. A book can stand for several at once. The
 * books owned go on a list named after LibraryThing, as every import of a
 * whole library does ({@see LibraryList}).
 */
enum LibraryThingShelf: string
{
    case Read = 'read';
    case Reading = 'reading';
    case Wanted = 'wanted';
    case Favorites = 'favorites';
    case Rated = 'rated';
    /** Owned: LibraryThing's own "Your library", the whole catalog. */
    case Owned = LibraryList::KEY;

    /** The translation key of its category in an import's selection; the books owned are {@see LibraryList}'s. */
    public function importLabel(): ?string
    {
        return match ($this) {
            self::Read => 'import.shelf_read',
            self::Reading => 'import.shelf_currently_reading',
            self::Wanted => 'import.watchlist',
            self::Favorites => 'common.favorites',
            self::Rated => 'common.ratings',
            self::Owned => null,
        };
    }
}

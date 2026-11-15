<?php

namespace App\Integrations\Goodreads;

/**
 * The exclusive shelves a Goodreads book stands on, one at a time: what the
 * book is to its reader. did-not-finish began as a shelf readers made
 * themselves; importers of the format (StoryGraph, Hardcover) read it as a
 * dropped book.
 */
enum GoodreadsShelf: string
{
    case Read = 'read';
    case CurrentlyReading = 'currently-reading';
    case ToRead = 'to-read';
    case DidNotFinish = 'did-not-finish';

    /** The translation key of its category in an import's selection. */
    public function importLabel(): string
    {
        return match ($this) {
            self::Read => 'import.shelf_read',
            self::CurrentlyReading => 'import.shelf_currently_reading',
            self::ToRead => 'import.watchlist',
            self::DidNotFinish => 'import.shelf_did_not_finish',
        };
    }
}

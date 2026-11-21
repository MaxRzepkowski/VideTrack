<?php

namespace App\Integrations\BookWyrm;

/**
 * The reading shelf a BookWyrm book sits on in an export, which keeps one
 * shelf a book. A shelf of the reader's own making goes by a name of its own
 * (`kitchen-12`) and is none of these.
 */
enum BookWyrmShelf: string
{
    case Read = 'read';
    case Reading = 'reading';
    case ToRead = 'to-read';
    case StoppedReading = 'stopped-reading';

    /** The translation key of its category in an import's selection. */
    public function importLabel(): string
    {
        return match ($this) {
            self::Read => 'import.shelf_read',
            self::Reading => 'import.shelf_currently_reading',
            self::ToRead => 'import.watchlist',
            self::StoppedReading => 'import.shelf_did_not_finish',
        };
    }
}

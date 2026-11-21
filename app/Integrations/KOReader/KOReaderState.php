<?php

namespace App\Integrations\KOReader;

/** Where a book of KOReader's statistics stands, as an import's selection offers it. */
enum KOReaderState: string
{
    case Read = 'read';
    case Reading = 'reading';

    /** The translation key of its category in an import's selection. */
    public function importLabel(): string
    {
        return match ($this) {
            self::Read => 'import.shelf_read',
            self::Reading => 'import.shelf_currently_reading',
        };
    }
}

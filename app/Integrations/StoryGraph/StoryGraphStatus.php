<?php

namespace App\Integrations\StoryGraph;

/**
 * The read status a StoryGraph book carries, one at a time. A book with
 * none sits only on the reader's own tags.
 */
enum StoryGraphStatus: string
{
    case Read = 'read';
    case CurrentlyReading = 'currently-reading';
    case Paused = 'paused';
    case ToRead = 'to-read';
    case DidNotFinish = 'did-not-finish';

    /** The translation key of its category in an import's selection. */
    public function importLabel(): string
    {
        return match ($this) {
            self::Read => 'import.shelf_read',
            self::CurrentlyReading => 'import.shelf_currently_reading',
            self::Paused => 'import.shelf_paused',
            self::ToRead => 'import.watchlist',
            self::DidNotFinish => 'import.shelf_did_not_finish',
        };
    }
}

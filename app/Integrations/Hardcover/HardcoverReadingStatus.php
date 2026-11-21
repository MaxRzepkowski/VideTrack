<?php

namespace App\Integrations\Hardcover;

/** A book's status in a Hardcover library (`user_books.status_id`). */
enum HardcoverReadingStatus: int
{
    case WantToRead = 1;
    case CurrentlyReading = 2;
    case Read = 3;
    case Paused = 4;
    case DidNotFinish = 5;
    /** The reader asked never to be shown the book: nothing of theirs to carry over. */
    case Ignored = 6;
}

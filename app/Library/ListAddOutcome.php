<?php

namespace App\Library;

/** What became of one title asked onto a list. */
enum ListAddOutcome: string
{
    case Added = 'added';
    /** On the list already: it stays where it was. */
    case Present = 'present';
    /** Its provider does not know the ref. */
    case NotFound = 'not_found';
    /** Its provider could not be asked (down, busy or refusing the instance), so it was not fetched. */
    case Unavailable = 'unavailable';
}

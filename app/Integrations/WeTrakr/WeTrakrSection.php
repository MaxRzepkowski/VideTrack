<?php

namespace App\Integrations\WeTrakr;

/**
 * The files of a WeTrakr export an import reads; a file's name starts with
 * its section. The notes and the profile hold nothing a library keeps.
 */
enum WeTrakrSection: string
{
    case Tracklog = 'tracklog';
    case Ratings = 'ratings';
    case Lists = 'lists';
    case Favorites = 'favorites';
}

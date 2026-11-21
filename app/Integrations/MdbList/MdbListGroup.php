<?php

namespace App\Integrations\MdbList;

/** The groups MDBList splits a sync answer into, by the kind of title. */
enum MdbListGroup: string
{
    case Movies = 'movies';
    case Shows = 'shows';
    case Seasons = 'seasons';
    case Episodes = 'episodes';
}

<?php

namespace App\Library;

/**
 * What the date of a viewing from elsewhere is, and so what tells one such
 * viewing from another when the same history arrives again.
 */
enum PlayDating
{
    /** When the viewing happened: two entries a moment apart are two viewings. */
    case Viewing;

    /**
     * When the source last saw activity on the title (a launcher's "last
     * played", a tracker's "updated"). It moves between two exports of the
     * same history, so it cannot tell viewings apart: one entry per run.
     */
    case Activity;
}

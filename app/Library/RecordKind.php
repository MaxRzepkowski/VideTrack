<?php

namespace App\Library;

/**
 * What kind of record an imported viewing comes from, and so what it is
 * weighed against before it is logged: a viewing another source already
 * logged a few hours off stands for it.
 */
enum RecordKind
{
    /** A tracker's history: one entry per viewing, so its own entries hours apart are two viewings. */
    case History;

    /**
     * A media server's playback log: one viewing may be several sessions,
     * and its webhook may have logged it already, so it is weighed as the
     * webhook is, against its own source too.
     */
    case PlaybackLog;

    /** This library's own history (a backup): it comes back as it was. */
    case Backup;
}

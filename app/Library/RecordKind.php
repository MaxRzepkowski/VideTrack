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
     * A playback log: one viewing may be several records (a media server's
     * sessions, a player that records a viewing again each time playback
     * passes the end), and a webhook may have logged it already, so it is
     * weighed as the webhook is, against its own source too.
     */
    case PlaybackLog;

    /** This library's own history (a backup): it comes back as it was. */
    case Backup;
}

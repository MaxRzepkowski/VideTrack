<?php

namespace App\Library;

/**
 * What clearing a status does with the sessions the open attempt logged:
 * resume the attempt with them, or throw the attempt away with them.
 */
enum LoggedSessions
{
    case Keep;
    case Discard;
}

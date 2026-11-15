<?php

namespace App\Library;

/**
 * What a completion does with a dropped attempt it follows in the same run:
 * finish that very attempt (the drop was a stage of it, its frozen hours and
 * the sessions since add up), finish it starting the counter fresh, or keep
 * the drop as a history entry of its own and close this one beside it
 * (games: playthrough 2 after an abandoned playthrough 1).
 */
enum AttemptPolicy
{
    case ReopenDropped;
    case ReopenFresh;
    case NewAttempt;
}

<?php

namespace App\Integrations\Tmdb;

/**
 * The `status_code` of a TMDB v3 error that changes what an account read
 * means (developer.themoviedb.org, Errors). Any other code is the answer's
 * HTTP status alone.
 */
enum TmdbStatus: int
{
    case AuthenticationFailed = 3;
    case InvalidApiKey = 7;
    case SuspendedApiKey = 10;
    case SessionDenied = 17;
    case SessionNotFound = 37;
    case ResourcePrivate = 39;

    /** The instance's own key was refused: nothing the user can fix. */
    public function refusesTheInstance(): bool
    {
        return $this === self::InvalidApiKey || $this === self::SuspendedApiKey;
    }
}

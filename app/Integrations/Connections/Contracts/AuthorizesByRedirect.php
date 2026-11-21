<?php

namespace App\Integrations\Connections\Contracts;

use App\Integrations\Connections\ConnectionRejected;
use App\Integrations\Connections\Data\ConnectionGrant;
use App\Integrations\Connections\Data\RedirectAuthorization;
use App\Support\ServiceUnavailable;

/**
 * The user approves on the service's own site and the service sends the
 * browser back to the instance (an OAuth authorization code, TMDB's
 * request token). What the instance must hold until they are back (a
 * verifier, the token to exchange) is kept server-side by the attempt,
 * never in the page.
 *
 * The attempt owns the check that a trip back is the one it started: it
 * hands the service a random state, which the service carries to its site
 * and back (OAuth's `state`, or one more segment of the return address's
 * path), and only an answer carrying it reaches completeRedirect().
 */
interface AuthorizesByRedirect extends ConnectableService
{
    /**
     * Where to send the user, carrying $state to the service and back, and
     * what to keep until they come back.
     *
     * @throws ConnectionRejected|ServiceUnavailable
     */
    public function beginRedirect(string $callbackUrl, string $state): RedirectAuthorization;

    /**
     * Turns the trip back into a grant; a refusal on the service's site is
     * a rejection.
     *
     * @param  array<string, string>  $answer  the query the service sent the browser back with
     * @param  array<string, string>  $kept  what beginRedirect() kept
     *
     * @throws ConnectionRejected|ServiceUnavailable
     */
    public function completeRedirect(array $answer, array $kept, string $callbackUrl): ConnectionGrant;
}

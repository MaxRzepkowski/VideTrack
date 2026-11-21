<?php

namespace App\Integrations\Connections;

use App\Support\NotFoundAtService;

/**
 * A 404 from the user's own server: the thing asked about is not there (an
 * item removed, a row merged away). Not an outage — the caller moves on.
 */
final class OwnServerNotFound extends NotFoundAtService {}

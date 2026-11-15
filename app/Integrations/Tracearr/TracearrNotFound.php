<?php

namespace App\Integrations\Tracearr;

use App\Support\NotFoundAtService;

/**
 * A 404 from Tracearr: the thing asked about is not there (a media row
 * merged away). Not an outage — the caller moves on.
 */
final class TracearrNotFound extends NotFoundAtService {}

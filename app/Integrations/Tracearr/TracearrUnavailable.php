<?php

namespace App\Integrations\Tracearr;

use App\Support\ServiceUnavailable;

/**
 * Tracearr could not be read this time — out of reach, failing on its side,
 * or rate-limited past the backoff. Asking again later may work.
 */
final class TracearrUnavailable extends ServiceUnavailable {}

<?php

namespace App\Integrations\Hardcover;

use App\Support\ServiceUnavailable;

/**
 * Hardcover could not be read this time — out of reach, failing on its
 * side, or rate-limited past the backoff. Asking again later may work.
 */
final class HardcoverUnavailable extends ServiceUnavailable {}

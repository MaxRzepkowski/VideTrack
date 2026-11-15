<?php

namespace App\Integrations\Simkl;

use App\Support\ServiceUnavailable;

/**
 * Simkl could not be read this time — out of reach, failing on its side, or
 * rate-limited past the backoff. Asking again later may work.
 */
final class SimklUnavailable extends ServiceUnavailable {}

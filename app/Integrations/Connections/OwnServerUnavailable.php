<?php

namespace App\Integrations\Connections;

use App\Support\ServiceUnavailable;

/**
 * The user's own server could not be read this time: out of reach, failing
 * on its side, or busy past the backoff. Asking again later may work.
 */
final class OwnServerUnavailable extends ServiceUnavailable {}

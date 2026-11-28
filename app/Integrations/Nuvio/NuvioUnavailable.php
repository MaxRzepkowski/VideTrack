<?php

namespace App\Integrations\Nuvio;

use App\Support\ServiceUnavailable;

/**
 * Nuvio could not be read this time — out of reach, failing on its side, or
 * busy past the retries. Asking again later may work.
 */
final class NuvioUnavailable extends ServiceUnavailable {}

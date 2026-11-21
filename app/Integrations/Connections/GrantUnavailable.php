<?php

namespace App\Integrations\Connections;

use App\Support\ServiceUnavailable;

/**
 * A service's authorization endpoints could not be used this time — out of
 * reach or failing on their side. The grant is untouched; asking again later
 * may work.
 */
final class GrantUnavailable extends ServiceUnavailable {}

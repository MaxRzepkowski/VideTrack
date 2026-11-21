<?php

namespace App\Integrations\MangaBaka;

use App\Support\ServiceUnavailable;

/**
 * MangaBaka could not be read this time: out of reach or failing on its
 * side. Asking again later may work.
 */
final class MangaBakaUnavailable extends ServiceUnavailable {}

<?php

namespace App\Integrations\Tmdb;

use App\Support\ServiceUnavailable;

/**
 * A TMDB account could not be read this time: out of reach or failing on
 * its side. Asking again later may work.
 */
final class TmdbUnavailable extends ServiceUnavailable {}

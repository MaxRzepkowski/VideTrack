<?php

namespace App\Integrations\Tmdb;

use App\Support\ServiceRejected;

/** TMDB answered and refused the request; asking again will not help. */
final class TmdbRejected extends ServiceRejected {}

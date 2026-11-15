<?php

namespace App\Integrations\Anime;

use App\Support\ServiceUnavailable;

/**
 * The tracker could not be read this time — out of reach, failing on its
 * side, or busy past our wait. Asking again later may work.
 */
final class AnimeListUnavailable extends ServiceUnavailable {}

<?php

namespace App\Integrations\Tmdb;

use App\Support\NotFoundAtService;

/** TMDB has nothing at that address: a list gone. Not an outage. */
final class TmdbNotFound extends NotFoundAtService {}

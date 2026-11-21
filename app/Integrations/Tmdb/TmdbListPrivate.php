<?php

namespace App\Integrations\Tmdb;

use App\Support\ServiceRejected;

/** A list TMDB keeps private from other apps ("This resource is private."); asking again will not show it. */
final class TmdbListPrivate extends ServiceRejected {}

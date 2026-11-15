<?php

namespace App\Integrations\Anime;

use App\Support\ServiceUnavailable;

/** The anime id map's host could not be reached or did not serve the file. The message is an i18n key. */
final class AnimeIdMapUnavailable extends ServiceUnavailable {}

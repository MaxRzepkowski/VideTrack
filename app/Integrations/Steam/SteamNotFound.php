<?php

namespace App\Integrations\Steam;

use App\Support\NotFoundAtService;

/** Steam knows no profile by that custom name. The message is an i18n key. */
final class SteamNotFound extends NotFoundAtService {}

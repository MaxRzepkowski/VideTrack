<?php

namespace App\Integrations\Steam;

use App\Support\ServiceUnavailable;

/**
 * Steam could not be reached or failed to answer; trying later may help.
 * The message is an i18n key.
 */
final class SteamUnavailable extends ServiceUnavailable {}

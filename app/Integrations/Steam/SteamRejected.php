<?php

namespace App\Integrations\Steam;

use App\Support\ServiceRejected;

/**
 * Steam answered and would not give the library: the user's key is
 * refused, or the profile keeps its games private. Asking again will not
 * help. The message is an i18n key.
 */
final class SteamRejected extends ServiceRejected {}

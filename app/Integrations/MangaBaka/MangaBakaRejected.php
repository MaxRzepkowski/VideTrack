<?php

namespace App\Integrations\MangaBaka;

use App\Support\ServiceRejected;

/** MangaBaka answered and refused the request; asking again will not help. */
final class MangaBakaRejected extends ServiceRejected {}

<?php

namespace App\Integrations\Tracearr;

use App\Support\ServiceRejected;

/** Tracearr answered and refused the request; asking again will not help. */
final class TracearrRejected extends ServiceRejected {}

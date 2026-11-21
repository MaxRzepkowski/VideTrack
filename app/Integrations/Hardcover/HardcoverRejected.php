<?php

namespace App\Integrations\Hardcover;

use App\Support\ServiceRejected;

/** Hardcover answered and refused the request; asking again will not help. */
final class HardcoverRejected extends ServiceRejected {}

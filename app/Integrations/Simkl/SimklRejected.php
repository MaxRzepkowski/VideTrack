<?php

namespace App\Integrations\Simkl;

use App\Support\ServiceRejected;

/** Simkl answered and refused the request; asking again will not help. */
final class SimklRejected extends ServiceRejected {}

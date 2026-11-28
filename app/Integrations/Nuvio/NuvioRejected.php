<?php

namespace App\Integrations\Nuvio;

use App\Support\ServiceRejected;

/**
 * Nuvio answered and refused, or answered in a shape its documentation does
 * not give: the same request would fare no better.
 */
final class NuvioRejected extends ServiceRejected {}

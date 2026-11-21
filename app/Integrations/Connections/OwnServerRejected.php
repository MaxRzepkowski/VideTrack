<?php

namespace App\Integrations\Connections;

use App\Support\ServiceRejected;

/** The user's own server answered and refused the request; asking again will not help. */
final class OwnServerRejected extends ServiceRejected {}

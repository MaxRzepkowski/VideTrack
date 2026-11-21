<?php

namespace App\Integrations\MdbList;

use App\Support\ServiceRejected;

/** MDBList answered and refused the request; asking again will not help. */
final class MdbListRejected extends ServiceRejected {}

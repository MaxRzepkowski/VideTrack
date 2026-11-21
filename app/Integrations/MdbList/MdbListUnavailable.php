<?php

namespace App\Integrations\MdbList;

use App\Support\ServiceUnavailable;

/**
 * MDBList could not be read this time — out of reach, failing on its side,
 * or past its rate limit. Asking again later may work.
 */
final class MdbListUnavailable extends ServiceUnavailable {}

<?php

namespace App\Catalog;

use App\Support\ServiceRejected;

/**
 * A catalogue provider answered and refused the request — a revoked or wrong
 * key, a request it does not allow. Asking again will not help, unlike a
 * {@see ProviderException}; a page treats both the same way, as data it
 * cannot have right now.
 */
class ProviderRejected extends ServiceRejected {}

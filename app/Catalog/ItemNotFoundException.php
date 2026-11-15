<?php

namespace App\Catalog;

use App\Support\NotFoundAtService;

/**
 * The requested position does not exist at the provider (upstream 404).
 */
class ItemNotFoundException extends NotFoundAtService {}

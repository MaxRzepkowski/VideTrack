<?php

namespace App\Catalog;

/**
 * The provider turned away the instance's own key or credentials (a 401, or
 * the service's own word for it): a refusal no request can avoid,
 * only a new key ends it.
 */
class ProviderKeyRejected extends ProviderRejected {}

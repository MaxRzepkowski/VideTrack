<?php

namespace App\Support;

/**
 * The service answered and refused what we sent: a wrong key, a revoked
 * grant, an address that is not the service. Asking again will not help.
 */
class ServiceRejected extends ExternalServiceException {}

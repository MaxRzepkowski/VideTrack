<?php

namespace App\Support;

/**
 * The service could not answer this time: down, timed out, an upstream
 * error, an answer we cannot read. Asking again later may work.
 */
class ServiceUnavailable extends ExternalServiceException {}

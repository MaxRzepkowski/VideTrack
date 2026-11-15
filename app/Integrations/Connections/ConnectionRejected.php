<?php

namespace App\Integrations\Connections;

use App\Support\ServiceRejected;

/**
 * The service turned down what the user gave it (a wrong key, an address
 * that is not the service). The message is an i18n key shown under the
 * form. Never retried.
 */
final class ConnectionRejected extends ServiceRejected {}

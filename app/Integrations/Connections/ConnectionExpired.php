<?php

namespace App\Integrations\Connections;

use App\Support\ServiceRejected;

/**
 * A connection that can no longer be used until the user connects again:
 * the service stopped honouring it (a revoked grant, a key that lost its
 * rights), or the instance no longer lets it reach its address. Nothing asks
 * the service again. The message is a translation key: the connection card
 * reads it in the viewer's language, the task run in the job's.
 */
final class ConnectionExpired extends ServiceRejected {}

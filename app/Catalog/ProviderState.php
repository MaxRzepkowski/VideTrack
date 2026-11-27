<?php

namespace App\Catalog;

/**
 * How a metadata provider has been answering lately. Only a refused key is
 * something the admin has to fix; the rest passes by waiting.
 */
enum ProviderState: string
{
    case Healthy = 'healthy';
    /** An outage, a dropped connection or a limit: it passes on its own. */
    case Degraded = 'degraded';
    /** The service answered and turned the instance's key away. */
    case KeyRejected = 'key-rejected';
}

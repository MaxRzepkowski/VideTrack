<?php

namespace App\Integrations\Connections;

/**
 * A device-flow poll that did not end in a grant. There is no "denied":
 * services following RFC 8628 loosely (Simkl) report a refusal as pending
 * forever, so the caller's own deadline is what ends a refused attempt.
 */
enum DevicePoll: string
{
    case Pending = 'pending';
    /** Polled too early; the interval grows by five seconds. */
    case SlowDown = 'slow_down';
    case Expired = 'expired';
}

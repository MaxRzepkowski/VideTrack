<?php

namespace App\Integrations\Scrobble;

use RuntimeException;

/**
 * A delivery that names no event at all — not the server's routine traffic
 * but something a source does not know how to read. Kept with what it sent,
 * because looking at it is the way to fix it.
 */
final class UnrecognizedPayload extends RuntimeException {}

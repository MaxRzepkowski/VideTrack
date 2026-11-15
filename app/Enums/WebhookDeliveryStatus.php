<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What became of one delivery. Only `Accepted` changed anything; the rest
 * are the log telling the user why nothing happened.
 */
#[TypeScript]
enum WebhookDeliveryStatus: string
{
    /** Parsed and queued; the job will settle it. */
    case Received = 'received';

    /** The event was applied to the library. */
    case Accepted = 'accepted';

    /** Understood, deliberately not applied (a duplicate, below threshold…). */
    case Ignored = 'ignored';

    /** An event we understood, about a title we could not place. */
    case Unmatched = 'unmatched';

    /** The payload was broken, or applying it failed. */
    case Error = 'error';

    /**
     * A dry run the user asked for from settings: the sample payload went
     * through parsing and resolving, and stopped short of the library.
     */
    case Test = 'test';
}

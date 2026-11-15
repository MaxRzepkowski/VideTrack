<?php

namespace App\Integrations\Scrobble;

use App\Integrations\Scrobble\Contracts\ProvidesSamplePayload;
use App\Integrations\Scrobble\Contracts\ScrobbleSource;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use Illuminate\Http\Request;

/**
 * A source's own example payload, delivered to its parser the way its server
 * would post it — the request a dry run needs, built where requests belong.
 */
final class SampleDelivery
{
    /** What the source reads in its own sample; null when it calls it nothing to act on. */
    public static function parse(ProvidesSamplePayload&ScrobbleSource $source): ?ScrobbleEvent
    {
        return $source->parse(Request::create('/', 'POST', $source->samplePayload()));
    }
}

<?php

namespace App\Integrations\Nuvio;

/** What a change to a Nuvio history did, as its `operation` says. */
enum NuvioOperation: string
{
    case Upsert = 'upsert';
    case Delete = 'delete';
}

<?php

namespace App\Integrations\Nuvio;

/** What a Nuvio history entry is, as its `content_type` says. */
enum NuvioContentType: string
{
    case Movie = 'movie';
    case Series = 'series';
}

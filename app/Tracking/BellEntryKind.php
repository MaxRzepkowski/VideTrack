<?php

namespace App\Tracking;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a row of the bell list is, in the order its filter lists them: a
 * followed creator (person, company, collection), a title rung by hand, one a
 * followed creator rang, or a muted one.
 */
#[TypeScript]
enum BellEntryKind: string
{
    case Person = 'person';
    case Company = 'company';
    case Collection = 'collection';
    case Manual = 'manual';
    case Via = 'via';
    case Muted = 'muted';
}

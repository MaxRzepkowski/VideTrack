<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What a company did on a title: a game's developer, publisher, porting or supporting studio, a film's studio, a show's network. */
#[TypeScript]
enum CompanyRole: string
{
    case Developer = 'developer';
    case Publisher = 'publisher';
    case Porting = 'porting';
    case Supporting = 'supporting';
    case Studio = 'studio';
    case Network = 'network';
}

<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How a discover filter is rendered and how it travels in the query string.
 * Backed by strings so the JSON contract with the frontend is explicit — the
 * case names are an implementation detail, the values are the protocol.
 */
#[TypeScript]
enum DiscoverFilterKind: string
{
    /** One value out of the filter's options, or none. */
    case Select = 'select';

    /** Any number of the filter's options (OR within the filter). */
    case MultiSelect = 'multi_select';

    /**
     * A numeric span between the filter's `rangeMin` and `rangeMax`: years,
     * minutes. It has no options and lives in the query string as two
     * separate keys — `{key}_from` and `{key}_to` — either of which may stand
     * alone for an open-ended span.
     */
    case Range = 'range';
}

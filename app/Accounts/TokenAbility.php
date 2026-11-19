<?php

namespace App\Accounts;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What an API token may do. Every token reads; writing is granted apart.
 * The values are what Sanctum stores and what the routes name in
 * `abilities:read` / `abilities:write`.
 */
#[TypeScript]
enum TokenAbility: string
{
    case Read = 'read';
    case Write = 'write';
}

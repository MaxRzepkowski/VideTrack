<?php

namespace App\Integrations;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What keeps feeding the library on its own: a media server that sends what
 * was watched, or an account this instance reads the history from.
 */
#[TypeScript]
enum IntegrationKind: string
{
    case Webhook = 'webhook';
    case Account = 'account';
}
